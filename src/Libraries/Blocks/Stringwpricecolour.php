<?php namespace CoasterCms\Libraries\Blocks;

use CoasterCms\Helpers\BlockManager;
use CoasterCms\Models\BlockSelectOption;
use Illuminate\Support\Facades\Request;

class Stringwpricecolour extends _Base
{

    public static $blocks_key = 'blockcp';

    public static function display($block, $block_data, $options = array())
    {
        if (!empty($block_data)) {
            return unserialize($block_data);
        } else {
            $text = new \stdClass;
            $text->text = '';
            $text->price = 0;
            $text->colour = '';
            return $text;
        }
    }

    public static function edit($block, $block_data, $page_id = 0, $parent_repeater = null)
    {
        $field_data = new \stdClass;
        $block_data = unserialize($block_data);
        $field_data->text = !isset($block_data->text) ? '' : $block_data->text;
        $field_data->colour = !isset($block_data->colour) ? '' : $block_data->colour;
        $field_data->price = !isset($block_data->price) ? '' : $block_data->price;

        $options = array('none' => 'Default Colour');
        $select_opts = BlockSelectOption::where('block_id', '=', $block->id)->get();
        foreach ($select_opts as $opts) {
            $options[$opts->value] = $opts->option;
        }
        $field_data->options = $options;
        $field_data->class = 'select_colour';

        self::$edit_id = array($block->id);
        return $field_data;
    }

    public static function submit($page_id, $blocks_key, $repeater_info = null)
    {
        $text_blocks = Request::input($blocks_key);
        if (!empty($text_blocks)) {
            foreach ($text_blocks as $block_id => $block_content) {
                $text = new \stdClass;
                $text->text = $block_content;
                $text->price = Request::input($blocks_key . '_price.' . $block_id);
                if (Request::input($blocks_key . '_colour.' . $block_id) != 'none') {
                    $text->colour = Request::input($blocks_key . '_colour.' . $block_id);
                }
                if (empty($text->text) && empty($text->price) && empty($text->colour)) {
                    $text = '';
                } else {
                    $text = serialize($text);
                }
                BlockManager::update_block($block_id, $text, $page_id, $repeater_info);
            }
        }
    }

    public static function search_text($block_content, $version = 0)
    {
        if (!empty($block_content)) {
            $block_content = unserialize($block_content);
            if (!empty($block_content->text)) {
                return strip_tags($block_content->text);
            }
        }
        return null;
    }

}