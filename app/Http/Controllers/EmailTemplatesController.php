<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Setting;
use Illuminate\Support\Facades\Validator;
use App\Utils\FileHelper;
use App\Services\EmailTranslator;
use App\Services\EmailPreview;
use App\Services\EmailConfigService;
use Illuminate\Mail\Mailable;

class EmailTemplatesController extends Controller
{
  public function index()
  {
    //get all email templates (all .twig file in /resources/views/emails)
    $template_files = glob(resource_path('views/emails/*.twig'));
    //load the content of each file
    $templates = [];
    foreach ($template_files as $template_file) {
      $templateName = basename($template_file, '.twig');
      //skip 'layout.twig'
      if ($templateName === 'layout') {
        continue;
      }
      // Strip version suffix (e.g., V2, V3) from display name
      $displayName = preg_replace('/V\d+$/', '', $templateName);
      
      // Get required variables from the original template (not customized)
      $originalContent = file_get_contents($template_file);
      $originalBlocks = $this->extractVariables($originalContent);
      $requiredVariables = array_merge(['subject'], array_keys($originalBlocks));
      
      $templates[] = [
        'name' => $displayName,
        'id' => $templateName,
        'content' => $this->getTemplateContent($template_file),
        'variables' => $this->extractVariables($this->getTemplateContent($template_file)),
        'subject' => EmailTranslator::subject(preg_replace('/V\d+$/', '', $templateName)),
        'requiredVariables' => $requiredVariables
      ];
    }
    return response()->json([
      'status' => 'success',
      'message' => 'Email templates fetched successfully',
      'data' => [
        'templates' => $templates
      ]
    ]);
  }

  /** Render unsaved blocks of a template with sample data. Nothing is stored or sent. */
  public function preview(Request $request, EmailPreview $preview)
  {
    $html = $this->renderDraft($request, $preview, true);

    return is_string($html) ? response()->json(['status' => 'success', 'data' => ['html' => $html]]) : $html;
  }

  /** Send the unsaved blocks, with sample data, to the signed-in administrator and nobody else. */
  public function sendTest(Request $request, EmailPreview $preview, EmailConfigService $mail)
  {
    $validator = Validator::make($request->all(), ['subject' => 'required|string|max:255']);
    if ($validator->fails()) {
      return response()->json(['status' => 'error', 'message' => 'Invalid template', 'data' => $validator->errors()], 422);
    }

    $html = $this->renderDraft($request, $preview, false);
    if (!is_string($html)) {
      return $html;
    }

    $to = $request->user()->email;
    $mailable = (new Mailable())->subject('[Test] ' . $request->input('subject'))->html($html);

    try {
      $mail->configureMailer()->to($to)->send($mail->configureMailable($mailable));
    } catch (\Throwable $e) {
      report($e);
      return response()->json(['status' => 'error', 'message' => 'The test e-mail could not be sent: ' . $e->getMessage()], 502);
    }

    return response()->json(['status' => 'success', 'data' => ['sent_to' => $to]]);
  }

  /** @return string|\Illuminate\Http\JsonResponse the rendered HTML, or the error to return */
  private function renderDraft(Request $request, EmailPreview $preview, bool $forBrowser)
  {
    $validator = Validator::make($request->all(), [
      'id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]+$/'],
      'variables' => 'required|array',
      'variables.header' => 'nullable|string|max:2000',
      'variables.content' => 'nullable|string|max:50000',
      'variables.action_text' => 'nullable|string|max:2000',
      'variables.footer' => 'nullable|string|max:10000',
    ]);

    if ($validator->fails()) {
      return response()->json(['status' => 'error', 'message' => 'Invalid template', 'data' => $validator->errors()], 422);
    }

    $originalPath = resource_path('views/emails/' . basename($request->input('id')) . '.twig');
    if ($request->input('id') === 'layout' || !file_exists($originalPath)) {
      return response()->json(['status' => 'error', 'message' => 'Template not found'], 404);
    }

    $blocks = array_intersect_key($request->input('variables'), array_flip(['header', 'content', 'action_text', 'footer']));
    // The link is read-only in the editor: always take it from the shipped template.
    $original = $this->extractVariables(file_get_contents($originalPath));
    if (isset($original['action_url'])) {
      $blocks['action_url'] = $original['action_url'];
    }

    try {
      return $preview->render($blocks, $forBrowser);
    } catch (\Twig\Error\Error $e) {
      return response()->json(['status' => 'error', 'message' => $e->getRawMessage()], 422);
    }
  }

  private function getTemplateContent($template_file)
  {
    //first see if we have the same filename in storage/templates/emails
    $template_file_path = storage_path('templates/emails/' . basename($template_file));
    if (file_exists($template_file_path)) {
      return file_get_contents($template_file_path);
    }
    return file_get_contents($template_file);
  }

  private function extractVariables($content)
  {
    $variables = [];
    //find content inside blocks like {% block name %}, extract the name and the content
    preg_match_all('/{% block (.*?) %}(.*?){% endblock %}/s', $content, $matches);
    foreach ($matches[1] as $key => $match) {
      $variables[$match] = $this->treatContent($matches[2][$key], $match);
    }
    return $variables;
  }

  private function getSubject($name)
  {
    $setting =  Setting::where('key', 'email_subject_' . $name)->first();
    if ($setting) {
      return $setting->value;
    }
    return null;
  }

  private function treatContent($content, $name)
  {
    if ($name == 'header' || $name == 'action_text' || $name == 'action_url') {
      $content = str_replace("\n", "", $content);
      $content = trim($content);
    }

    if ($name == 'content') {
      //remove leading and trailing newlines
      $content = trim($content, "\n");
    }
    return $content;
  }

  public function update(Request $request)
  {
    $templates = $request->all();
    foreach ($templates as $template) {
      // Validate template ID to prevent path traversal attacks
      $templateId = $template['id'] ?? '';
      if (!preg_match('/^[a-zA-Z0-9_-]+$/', $templateId)) {
        return response()->json([
          'status' => 'error',
          'message' => 'Invalid template ID',
          'data' => []
        ], 400);
      }
      
      // Build dynamic validation rules based on original template
      $originalPath = resource_path('views/emails/' . $templateId . '.twig');
      if (!file_exists($originalPath)) {
        return response()->json([
          'status' => 'error',
          'message' => 'Template not found: ' . $templateId,
          'data' => []
        ], 404);
      }
      $originalContent = file_get_contents($originalPath);
      $originalBlocks = $this->extractVariables($originalContent);

      $rules = [
        'content' => 'required|string',
        'id' => 'required|string',
        'subject' => 'required|string',
        'variables.footer' => 'nullable|string|max:10000',
      ];

      // Only require blocks that exist in the original template
      foreach (['header', 'content', 'action_text', 'action_url'] as $block) {
        if (array_key_exists($block, $originalBlocks)) {
          $rules["variables.$block"] = 'required|string';
        }
      }

      $validator = Validator::make($template, $rules);

      if ($validator->fails()) {
        return response()->json([
          'status' => 'error',
          'message' => 'Invalid template',
          'data' => $validator->errors()
        ], 422);
      }

    }

    foreach ($templates as $template) {
      $this->updateTemplate($template);
    }

    return response()->json([
      'status' => 'success',
      'message' => 'Email templates updated successfully',
      'data' => $templates
    ]);
  }

  private function updateTemplate($template)
  {
    // Template ID should already be validated by update() method
    // but add basename() as defense-in-depth
    $safeId = basename($template['id']);
    
    $template_file_path = storage_path('templates/emails/' . $safeId . '.twig');
    // A subject left at the translated default is not stored, so it keeps following the language.
    $baseId = preg_replace('/V\d+$/', '', $safeId);
    if ($template['subject'] !== EmailTranslator::subject($baseId)) {
      Setting::updateOrCreate(
        ['key' => 'email_subject_' . $baseId . '.twig'],
        ['value' => $template['subject'], 'group' => 'system.emails.subjects']
      );
    }
    if (!file_exists(dirname($template_file_path))) {
      mkdir(dirname($template_file_path), 0755, true);
    }
    $date = date('Y-m-d H:i:s');

    // Conditionally build block strings - only include if they exist in the template
    $actionUrlBlock = isset($template['variables']['action_url'])
      ? "{% block action_url %}{$template['variables']['action_url']}{% endblock %}"
      : '';
    $actionTextBlock = isset($template['variables']['action_text'])
      ? "{% block action_text %}{$template['variables']['action_text']}{% endblock %}"
      : '';

    // Optional footer: when empty, the layout shows its default "automatic message" line
    $footer = trim((string) ($template['variables']['footer'] ?? ''));
    $footerBlock = $footer !== '' ? "{% block footer %}{$footer}{% endblock %}" : '';

    $template_twig = <<<TWIG
      {% extends 'emails/layout' %}
      {% block header %}{$template['variables']['header']}{% endblock %}
      {% block content %}{$template['variables']['content']}{% endblock %}
      {$actionUrlBlock}
      {$actionTextBlock}
      {$footerBlock}
      {# file generated at {$date} #}
    TWIG;

    file_put_contents($template_file_path, $template_twig);
  }
}
