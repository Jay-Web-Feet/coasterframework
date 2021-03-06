{!! Form::open(['url' => Request::url()]) !!}

        <!-- password field -->
<div class="form-group {!! FormMessage::get_class('blog_login') !!}">
    {!! Form::label('blog_login', 'Blog Login', ['class' => 'control-label']) !!}
    {!! Form::text('blog_login', $blog_login, ['class' => 'form-control']) !!}
    <span class="help-block">{!! FormMessage::get_message('blog_login') !!}</span>
</div>

<!-- confirm password field -->
<div class="form-group {!! FormMessage::get_class('blog_password') !!}">
    {!! Form::label('blog_password', 'Blog Password', ['class' => 'control-label']) !!}
    {!! Form::password('blog_password', ['class' => 'form-control']) !!}
    <span class="help-block">{!! FormMessage::get_message('blog_password') !!}</span>
</div>

<!-- submit button -->
{!! Form::submit('Update Details', ['class' => 'btn btn-primary']) !!}

{!! Form::close() !!}