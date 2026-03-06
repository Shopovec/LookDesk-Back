<!doctype html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body>
<p>Hello{{ $user->name ? ', ' . e($user->name) : '' }}!</p>

<p>Your account has been created.</p>

<p><b>Login:</b> {{ $user->email }}<br>
<b>Password:</b> {{ $password }}</p>

<p>Please sign in and change your password after login.</p>
</body>
</html>