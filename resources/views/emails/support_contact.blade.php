<p><b>Name:</b> {{ $data['name'] }}</p>
<p><b>Email:</b> {{ $data['email'] }}</p>
<p><b>Subject:</b> {{ $data['subject'] }}</p>
<p><b>Message:</b></p>
<p>{!! nl2br(e($data['message'])) !!}</p>

<hr>

@if(!empty($data['user_id']))
<p><b>User ID:</b> {{ $data['user_id'] }}</p>
@endif
@if(!empty($data['ip']))
<p><b>IP:</b> {{ $data['ip'] }}</p>
@endif