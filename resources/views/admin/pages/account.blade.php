<h1>Account Settings</h1>

<br/>

{!! $account !!}

@if ($change_password)
    <a href="{{ URL::Current().'/password' }}" class="btn btn-warning"><i class="fa fa-unlock-alt"></i> &nbsp; Change
        Password</a>
@endif

@if ($auto_blog_login)
    {{ ($change_password)?'&nbsp;':'' }}
    <a href="{{ URL::Current().'/blog' }}" class="btn btn-warning"><i class="fa fa-share"></i> &nbsp; Auto Blog Login
        Details</a>
@endif