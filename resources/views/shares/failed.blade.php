<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ $settings['application_name'] }}</title>
  @vite('resources/js/app.js')

</head>

<body data-settings='{!! json_encode($settings) !!}'>
  <link rel="stylesheet" href="/status.css">
  <div class="share-not-ready">
    <div class="share-status-inner">
      <h1>Bad Error</h1>
      <p>Something went horribly wrong with this share. <br><small>Please ask the sender to try again.</small></p>
    </div>
  </div>
</body>

</html>
