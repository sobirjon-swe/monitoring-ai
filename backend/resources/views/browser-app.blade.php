<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring AI</title>
    @foreach ($styles as $style)
        <link rel="stylesheet" href="{{ $style }}">
    @endforeach
    <script type="module" src="{{ $script }}"></script>
</head>
<body>
    <div id="root"></div>
</body>
</html>
