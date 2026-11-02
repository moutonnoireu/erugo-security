<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\EmailBrand;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/** Logo and logo plate colour used in e-mails. Separate from the app logo: e-mails need a raster image. */
class EmailBrandingController extends Controller
{
  public function show()
  {
    return $this->payload();
  }

  public function updatePlate(Request $request)
  {
    $validator = Validator::make($request->all(), ['plate' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']]);
    if ($validator->fails()) {
      return response()->json(['status' => 'error', 'message' => 'Invalid colour', 'errors' => $validator->errors()], 422);
    }
    Setting::updateOrCreate(
      ['key' => 'email_logo_background'],
      ['value' => strtolower($request->input('plate')), 'group' => 'system.emails']
    );
    app(SettingsService::class)->clearCache();
    return $this->payload();
  }

  public function uploadLogo(Request $request)
  {
    $validator = Validator::make($request->all(), ['logo' => 'required|file|mimes:png,jpg,jpeg|max:1024']);
    if ($validator->fails()) {
      return response()->json(['status' => 'error', 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
    }

    $file = $request->file('logo');
    $info = @getimagesize($file->getRealPath());
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
      return response()->json(['status' => 'error', 'message' => 'The file is not a PNG or JPEG image'], 422);
    }
    if ($info[0] > 1200 || $info[1] > 600) {
      return response()->json(['status' => 'error', 'message' => 'The logo must not exceed 1200 x 600 pixels'], 422);
    }

    $this->removeLogos();
    $target = $info[2] === IMAGETYPE_PNG ? EmailBrand::LOGO_FILES[0] : EmailBrand::LOGO_FILES[1];
    Storage::disk('public')->put($target, file_get_contents($file->getRealPath()));

    return $this->payload();
  }

  public function deleteLogo()
  {
    $this->removeLogos();
    return $this->payload();
  }

  private function removeLogos(): void
  {
    foreach (EmailBrand::LOGO_FILES as $path) {
      Storage::disk('public')->delete($path);
    }
  }

  private function payload()
  {
    $brand = EmailBrand::toArray();
    return response()->json([
      'status' => 'success',
      'message' => 'Email branding fetched successfully',
      'data' => [
        'logo_url' => $brand['logo_url'],
        'plate' => $brand['plate'],
        'accent' => $brand['accent'],
        'accent_ink' => $brand['accent_ink'],
        'application_url' => $brand['url'],
      ],
    ]);
  }
}
