<?php $source_field_id = str_replace(array('[', ']'), array('_', ''), $name . '[source]'); ?>
<?php $image_preview_id = str_replace(array('[', ']'), array('_', ''), $name . '[image]'); ?>

<div class="form-group">
    {!! Form::label($name, $label, ['class' => 'control-label col-sm-2']) !!}

    <div class="col-sm-3">
        <div class="thumbnail maxthumbnail">
            @if (!empty($content->file))
                <a class="fancybox" href="{!! $content->file !!}">
                    <img id="{!! $image_preview_id !!}" alt="{!! $content->title !!}"
                         src="{!! Croppa::url($content->file, 200, 150) !!}"/>
                </a>
            @else
                <img id="{!! $image_preview_id !!}" alt="No Image"
                     src="http://www.placehold.it/200x150/EFEFEF/AAAAAA&text=no+image"/>
            @endif
        </div>
    </div>

    <div class="col-sm-7">
        <label>Image Source:</label>
        <div class="input-group">
            {!! Form::text($name.'[source]', $content->file, ['id' => $source_field_id, 'class' => 'img_src form-control']) !!}
            <span class="input-group-btn">
                <a href="{!! URL::to(config('coaster::admin.public').'/filemanager/dialog.php?type=1&field_id='.$source_field_id) !!}"
                   class="btn btn-default iframe-btn">Select Image</a>
            </span>
        </div>
        <label style="clear:both; margin-top:20px;">Image Title: </label>
        {!! Form::text($name.'[alt]', $content->title, ['class' => 'form-control']) !!}
    </div>

</div>

