<?php namespace CoasterCms\Libraries\Blocks;

use CoasterCms\Libraries\Builder\PageBuilder;
use CoasterCms\Models\BlockSelectOption;
use CoasterCms\Models\Page;
use CoasterCms\Models\PageBlock;
use CoasterCms\Models\PageLang;
use Illuminate\Support\Facades\View;

class Selectpages extends _Base
{

    public static function display($block, $block_data, $options = null)
    {
        $pages = [];
        $page_ids = [];
        if (isset($options['reverse'])) {
            // get page_ids on which current page is selected in this block
            if (!empty(PageBuilder::$page_info->page_id)) {
                $same_blocks = PageBlock::where('block_id', '=', $block->id)->get();
                foreach ($same_blocks as $same_block) {
                    $block_page_ids = @unserialize($same_block->content);
                    if (!empty($block_page_ids)) {
                        foreach ($block_page_ids as $k => $block_page_id) {
                            // if comma remove it (used for group page url, not used in getting content)
                            $string = explode(',', $block_page_id);
                            if ($string[0] == PageBuilder::$page_info->page_id) {
                                $page_ids[] = $same_block->page_id;
                                break;
                            }
                        }
                    }
                }
            }
        } elseif (!empty($block_data)) {
            $page_ids = unserialize($block_data);
        }
        if (!empty($page_ids)) {
            foreach ($page_ids as $page_id) {
                $paths = PageLang::get_full_path($page_id);
                $data = new \stdClass;
                $data->id = $page_id;
                $data->name = $paths->name;
                $data->full_name = $paths->full_name;
                $data->url = $paths->full_url;
                $pages[] = $data;
            }
        }
        $template = !empty($options['view']) ? $options['view'] : $block->name;
        if (View::exists('themes.' . PageBuilder::$theme . '.blocks.selectpages.' . $template)) {
            return View::make('themes.' . PageBuilder::$theme . '.blocks.selectpages.' . $template, array('pages' => $pages))->render();
        } else {
            return 'Select pages template not found';
        }
    }

    public static function edit($block, $block_data, $page_id = 0, $parent_repeater = null)
    {
        $list_options = array();
        $parent = BlockSelectOption::where('block_id', '=', $block->id)->where('option', '=', 'parent')->first();
        if (!empty($parent)) {
            $list_options['parent'] = $parent->value;
        }
        $field_data = new \stdClass;
        $field_data->options = Page::get_page_list($list_options);
        $field_data->selected = unserialize($block_data);
        self::$edit_id = array($block->id);
        return $field_data;
    }

    public static function save($block_content)
    {
        if (!empty($block_content)) {
            return serialize($block_content);
        } else {
            return '';
        }
    }

    public static function filter($block_id, $search, $type)
    {
        $live_blocks = PageBlock::page_blocks_on_live_page_versions($block_id);
        $page_ids = array();
        if (!empty($live_blocks)) {
            foreach ($live_blocks as $live_block) {
                $items = !empty($live_block->content) ? unserialize($live_block->content) : array();
                switch ($type) {
                    case '=':
                        if (in_array($search, $items)) {
                            $page_ids[] = $live_block->page_id;
                        }
                        break;
                    case 'in':
                        foreach ($items as $item) {
                            if (strpos($item, $search) !== false) {
                                $page_ids[] = $live_block->page_id;
                            }
                        }
                        break;
                }
            }
        }
        return $page_ids;
    }

}