<?php namespace CoasterCms\Http\Controllers\Backend;

use CoasterCms\Models\AdminLog;
use CoasterCms\Models\Menu;
use CoasterCms\Models\MenuItem;
use CoasterCms\Models\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\View;

class MenusController extends _Base
{

    private $page_names = '';

    private function preload_menu_item_names()
    {
        $this->page_names = Page::get_page_list();
    }

    public function get_index()
    {
        $this->preload_menu_item_names();
        $menus = '';
        $menu_item_info = new \stdClass;
        $menu_item_info->permissions['delete'] = Auth::action('menus.delete');
        $menu_item_info->permissions['subpage'] = Auth::action('menus.save_levels');
        $menu_item_info->permissions['rename'] = Auth::action('menus.rename');
        foreach (Menu::all() as $menu) {
            $menu_items = $menu->items()->get();
            $menus_li = '';
            $menu_item_info->max_sublevel = $menu->max_sublevel;
            foreach ($menu_items as $menu_item) {
                if (isset($this->page_names[$menu_item->page_id])) {
                    $menu_item_info->custom_name = trim($menu_item->custom_name);
                    $menu_item_info->custom_name = !empty($menu_item_info->custom_name) ? '&nbsp;(Custom Name: ' . $menu_item_info->custom_name . ')' : null;
                    $menu_item_info->name = $this->page_names[$menu_item->page_id];
                    $menu_item_info->id = $menu_item->id;
                    $menu_item_info->sub_levels = $menu_item->sub_levels;
                    $menus_li .= View::make('coaster::partials.menus.li', array('item' => $menu_item_info));
                }
            }
            $menus .= View::make('coaster::partials.menus.ol', array('menus_li' => $menus_li, 'menu' => $menu, 'can_add_item' => Auth::action('menus.add')));
        }
        $this->layout->content = View::make('coaster::pages.menus', array('menus' => $menus));
        $this->layout->modals = View::make('coaster::modals.menus.add_item', array('options' => Page::get_page_list())) .
            View::make('coaster::modals.general.delete_item') .
            View::make('coaster::modals.menus.edit_item') .
            View::make('coaster::modals.menus.rename_item');
    }

    public function post_add()
    {
        $menu_id = Request::input('menu');
        $page_id = Request::input('id');
        $last_item = MenuItem::where('menu_id', '=', $menu_id)->orderBy('order', 'desc')->first();
        if (!empty($last_item)) {
            $order = $last_item->order + 1;
        } else {
            $order = 1;
        }
        $new_item = new MenuItem;
        $new_item->menu_id = $menu_id;
        $new_item->page_id = $page_id;
        $new_item->order = $order;
        $new_item->sub_levels = 0;
        $new_item->save();
        $this->preload_menu_item_names();
        $item_name = $this->page_names[$page_id];
        AdminLog::new_log('Menu Item \'' . $item_name . '\' added to \'' . Menu::name($menu_id) . '\'');
        return $new_item->id;
    }

    public function post_delete($item_id)
    {
        $menu_item = MenuItem::find($item_id);
        if (!empty($menu_item)) {
            $this->preload_menu_item_names();
            // log action
            $item_name = $this->page_names[$menu_item->page_id];
            $log_id = AdminLog::new_log('Menu Item \'' . $item_name . '\' deleted from \'' . Menu::name($menu_item->menu_id) . '\'');
            $menu_item->delete();
            return $log_id;
        }
    }

    public function post_sort()
    {
        $order = 1;
        $items = Request::input('list');
        $menuId = 0;
        foreach ($items as $item) {
            $current_item = MenuItem::find($item);
            $current_item->order = $order++;
            $current_item->save();
            $menuId = $current_item->menu_id;
        }
        AdminLog::new_log('Items re-ordered in menu \'' . Menu::name($menuId) . '\'');
        return 1;
    }

    public function post_get_levels()
    {
        $item_id = substr(Request::input('id'), 5);
        $item = MenuItem::find($item_id);
        if (!empty($item)) {
            return $item->sub_levels;
        }
        return null;
    }

    public function post_save_levels()
    {
        $item_id = substr(Request::input('id'), 5);
        $item = MenuItem::find($item_id);
        if (!empty($item)) {
            $item->sub_levels = (int)Request::input('sub_level');
            $item->save();
            $this->preload_menu_item_names();
            // log action
            $item_name = $this->page_names[$item->page_id];
            AdminLog::new_log('Change sub levels for menu item \'' . $item_name . '\' in \'' . Menu::name($item->menu_id) . '\' to ' . $item->sub_levels);
            return 1;
        }
        return null;
    }

    public function post_rename()
    {
        $item_id = substr(Request::input('id'), 5);
        $item = MenuItem::find($item_id);
        if (!empty($item)) {
            $item->custom_name = Request::input('custom_name');
            $item->save();
            $this->preload_menu_item_names();
            // log action
            $item_name = $this->page_names[$item->page_id];
            if ($item->custom_name) {
                AdminLog::new_log('Renamed menu item \'' . $item_name . '\' in \'' . Menu::name($item->menu_id) . '\' to ' . $item->custom_name);
            } else {
                AdminLog::new_log('Removed custom name for menu item \'' . $item_name . '\' in \'' . Menu::name($item->menu_id) . '\'');
            }
            return 1;
        }
        return null;
    }

}