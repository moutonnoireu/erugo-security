<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="icon" href="/api/favicon" type="image/svg+xml">
  <title>{{ $settings['application_name'] }}</title>
  @vite('resources/js/app.js')
 
</head>


<body data-settings='{!! htmlspecialchars(json_encode($settings)) !!}' data-version="{{ config('app.version') }}" data-error="{{ session()->get('error') }}" data-success="{{ session()->get('success') }}">
  
<link rel="stylesheet" id="erugo-css-variables" href="/theme.css">
  <div id="app"></div>
</body>

</html>