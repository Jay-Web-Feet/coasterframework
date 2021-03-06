<?php namespace CoasterCms\Http\Controllers\Backend;

use CoasterCms\Helpers\View\FormMessage;
use CoasterCms\Helpers\View\PaginatorRender;
use CoasterCms\Helpers\BlockManager;
use CoasterCms\Libraries\Blocks\Datetime;
use CoasterCms\Models\AdminLog;
use CoasterCms\Models\Block;
use CoasterCms\Models\BlockBeacon;
use CoasterCms\Models\Language;
use CoasterCms\Models\Menu;
use CoasterCms\Models\MenuItem;
use CoasterCms\Models\Page;
use CoasterCms\Models\PageBlock;
use CoasterCms\Models\PageGroup;
use CoasterCms\Models\PageLang;
use CoasterCms\Models\PagePublishRequests;
use CoasterCms\Models\PageSearchData;
use CoasterCms\Models\PageVersion;
use CoasterCms\Models\Template;
use CoasterCms\Models\Theme;
use CoasterCms\Models\UserRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;

class PagesController extends _Base
{

    private $child_pages;

    public function get_index()
    {
        $numb_galleries = Template::blocks_of_type('gallery');
        $numb_forms = Template::blocks_of_type('form');

        $add_perm = Auth::action('pages.add');

        $pages = Page::orderBy('order', 'asc')->get();
        $this->child_pages = array();

        foreach ($pages as $page) {
            $page->number_of_galleries = !empty($numb_galleries[$page->template]) ? $numb_galleries[$page->template] : 0;
            $page->number_of_forms = !empty($numb_forms[$page->template]) ? $numb_forms[$page->template] : 0;
            if (!isset($this->child_pages[$page->parent])) {
                $this->child_pages[$page->parent] = array();
            }
            array_push($this->child_pages[$page->parent], $page);
        }

        $groups_exist = (bool) (PageGroup::count() > 0);

        $this->layout->content = View::make('coaster::pages.pages', array('pages' => $this->_list_pages(0, 1), 'add_page' => $add_perm, 'page_states' => Auth::user()->getPageStates(), 'max' => Page::at_limit(), 'groups_exist' => $groups_exist));
        $this->layout->modals = View::make('coaster::modals.general.delete_item');
    }

    public function get_add($page_id = 0)
    {
        $this->layout->content = $this->_load_page_data(0, array('parent' => $page_id));
    }

    public function post_add($page_id = 0)
    {
        $input = Request::all();
        $page_info = $input['page_info'];
        $page = Page::find($page_id);
        $in_group = !empty($page) ? $page->in_group : 0; // ignore page limit for group pages
        if (Page::at_limit() && $page_info['link'] != 1 && $in_group) {
            $this->layout->content = 'Page Limit Reached';
        } else {
            $new_page_id = $this->_save_page_info();
            if ($new_page_id === false) {
                $this->get_add($page_id);
            } else {
                return Redirect::action('\CoasterCms\Http\Controllers\Backend\PagesController@get_edit', array($new_page_id));
            }
        }
    }

    public function get_edit($page_id, $version = 0)
    {
        $this->layout->content = $this->_load_page_data($page_id, array('version' => $version));
    }

    public function post_edit($page_id)
    {
        $existingPage = Page::find($page_id);

        // run if duplicate button was hit
        if (Request::input('duplicate') == 1) {
            if ($existingPage->in_group) {
                $page_group = PageGroup::find($existingPage->in_group);
                $duplicate_parent = $page_group->default_parent;
            } else {
                $duplicate_parent = $existingPage->parent;
            }
            if (Auth::action('pages.add', ['page_id' => $duplicate_parent])) {
                $page_lang_model = PageLang::preload($page_id);
                $page_info_lang = Request::input('page_info_lang');
                $page_info_lang['name'] = $page_lang_model->name.' Duplicate';
                $page_info_lang['url'] = $page_lang_model->url.'-duplicate';
                $page_info = Request::input('page_info');
                $page_info['parent'] = $duplicate_parent;
                $page_info['group_container'] = $existingPage->group_container;
                Request::merge(array('page_info' => $page_info, 'page_info_lang' => $page_info_lang));

                $new_page_id = $this->_save_page_info();
                BlockManager::process_submission($new_page_id);
                return Redirect::action('\CoasterCms\Http\Controllers\Backend\PagesController@get_edit', array($new_page_id));
            } else {
                return abort(403, 'Action not permitted');
            }
        }

        // notify user
        $alert = new \stdClass;
        $alert->type = 'success';
        $alert->header = 'Page Content Updated';
        $alert->content = '';

        $new_version = PageVersion::add_new($page_id);
        BlockManager::$to_version = $new_version->version_id;

        $publishing = config('coaster::admin.publishing') ? true : false;
        $canPublish = Auth::action('pages.version-publish', ['page_id' => $page_id]);
        if ($publishing && $existingPage->link == 0) {
            // check if publish
            if (Request::input('publish') != '' && $canPublish) {
                BlockManager::$publish = true;
                $new_version->publish();
                // check if there were requests to publish the version being edited
                if (Request::input('overwriting_version_id')) {
                    $overwriting_page_version = PageVersion::where('version_id', '=', Request::input('overwriting_version_id'))->where('page_id', '=', $page_id)->first();
                    $requests = PagePublishRequests::where('page_version_id', '=', $overwriting_page_version->id)->where('status', '=', 'awaiting')->get();
                    if (!$requests->isEmpty()) {
                        foreach ($requests as $request) {
                            $request->status = 'approved';
                            $request->mod_id = Auth::user()->id;
                            $request->save();
                        }
                    }
                }
            }
            // check if publish request
            if (Request::input('publish_request') != '') {
                PagePublishRequests::add($page_id, $new_version->version_id, Request::input('request_note'));
            }
        } elseif (!$publishing || ($existingPage->link == 1 && $canPublish)) {
            BlockManager::$publish = true;
            $new_version->publish();
        }

        // update blocks
        BlockManager::process_submission($page_id);

        // save page info
        if ($this->_save_page_info($page_id) === false) {
            $alert->type = 'warning';
            $alert->content .= 'Error: "Page Info" not updated (check tab for errors)';
        }

        //send alert
        $this->layout->alert = $alert;

        // display page edit form
        $this->get_edit($page_id, BlockManager::$to_version);
    }

    public function post_sort()
    {
        $pages = Request::input('list');
        $order = array();
        $logged = [];
        $homePage = Page::join('page_lang', 'page_lang.page_id', '=', 'pages.id')->where(function ($query) {
            $query->where('page_lang.url', '=', '/')->orWhere('page_lang.url', '=', '');
        })->where('page_lang.language_id', '=', Language::current())->where('parent', '=', 0)->first();
        $homePageId = $homePage->page_id;
        if (!empty($pages)) {
            foreach ($pages as $pageId => $parent) {
                $currentPage = Page::preload($pageId);
                if (empty($currentPage))
                    return 0;
                $parent = (!empty($parent) && $parent != 'null' && $parent != $homePageId) ? $parent : 0;
                if (!isset($order[$parent]))
                    $order[$parent] = 1;
                else
                    $order[$parent]++;
                if (($currentPage->parent != $parent || $currentPage->order != $order[$parent])) {
                    if (Auth::action('pages.sort', ['page_id' => $parent]) && Auth::action('pages.sort', ['page_id' => $currentPage->parent])) {
                        $parentPageName = $parent ? PageLang::preload($parent)->name : '-- Top Level Page --';
                        if ($parent != $currentPage->parent) {
                            $logged[$parent] = true;
                            $logged[$currentPage->parent] = true;
                            AdminLog::new_log('Moved page \'' . PageLang::preload($pageId)->name . '\' under \'' . $parentPageName . '\' (Page ID ' . $currentPage->id . ')');
                        }
                        if (!isset($logged[$parent])) {
                            $logged[$parent] = true;
                            AdminLog::new_log('Re-ordered pages in \'' . $parentPageName . '\' (Page ID ' . $currentPage->id . ')');
                        }
                        $currentPage->parent = $parent;
                        $currentPage->order = $order[$parent];

                        $currentPage->save();
                    } else {
                        return 0;
                    }
                }
            }
        }
        return 1;
    }

    public function post_delete($page_id)
    {
        $page = Page::find($page_id);
        if (!empty($page)) {
            // backup/delete
            $log_id = $page->delete();
            return $log_id;
        }
        return 0;
    }

    public function post_versions($page_id)
    {
        return BlockManager::version_table($page_id);
    }

    public function post_version_rename($page_id)
    {
        $version_name = Request::input('version_name');
        $version_id = Request::input('version_id');
        if (!empty($page_id) && !empty($version_id)) {
            $page_version = PageVersion::where('page_id', '=', $page_id)->where('version_id', '=', $version_id)->first();
            if (!empty($page_version) && ($page_version->user_id == Auth::user()->id || Auth::action('pages.version-publish', ['page_id' => $page_id]))) {
                $page_version->label = $version_name;
                $page_version->save();
                return 1;
            }
        }
        return 0;
    }

    public function post_version_publish($page_id)
    {
        $version_id = Request::input('version_id');
        if (!empty($page_id) && !empty($version_id)) {
            $page_version = PageVersion::where('page_id', '=', $page_id)->where('version_id', '=', $version_id)->first();
            if (!empty($page_version)) {
                return $page_version->publish();
            }
        }
        return 0;
    }

    public function post_requests($page_id)
    {
        if (empty($page_id)) {
            // block access to all requests
            return 0;
        }

        $type = Request::input('request_type');
        $type = $type ? ['status' => $type] : [];

        $show = Request::input('request_show');
        $show = $show ?: ['page' => false, 'status' => true, 'requested_by' => true];


        $requests = PagePublishRequests::all_requests($page_id, $type, 25);
        if ($requests->isEmpty()) {
            $requests = 'No awaiting requests';
            $pagination = '';
        } else {
            $pagination = PaginatorRender::run($requests, config('coaster::admin.bootstrap_version'));
        }
        return View::make('coaster::partials.tabs.publish_requests.table', array('show' => $show, 'requests' => $requests, 'pagination' => $pagination))->render();

    }

    public function post_request_publish($page_id)
    {
        $version_id = Request::input('version_id');
        $note = Request::input('note');
        return PagePublishRequests::add($page_id, $version_id, $note);
    }

    public function post_request_publish_action($page_id)
    {
        $request_id = Request::input('request');
        $request = PagePublishRequests::with('page_version')->find($request_id);
        if (!empty($request)) {
            $request_action = Request::input('request_action');
            return $request->process($request_action);
        } else {
            return 0;
        }
    }

    public function getTinymcePageList()
    {
        $pages = array();
        $all_pages = Page::all();
        foreach ($all_pages as $page) {
            if (config('coaster::admin.advanced_permissions') && !Auth::action('pages', ['page_id' => $page->id])) {
                continue;
            }
            $pages[] = $page->id;
        }
        $page_details = PageLang::get_full_paths($pages, ' » ');
        $json_array = array();
        foreach ($page_details as $page_detail) {
            $details = new \stdClass;
            $details->title = $page_detail->full_name;
            $details->value = $page_detail->full_url;
            $json_array[] = $details;
        }
        usort($json_array, function ($a, $b) {
            return strcmp($a->title, $b->title);
        });
        return json_encode($json_array);
    }

    private function _list_pages($parent, $level, $cat_url = '')
    {

        if (isset($this->child_pages[$parent])) {
            $pages_li = '';
            $li_info = new \stdClass;
            foreach ($this->child_pages[$parent] as $child_page) {

                if (config('coaster::admin.advanced_permissions') && !Auth::action('pages', ['page_id' => $child_page->id])) {
                    continue;
                }

                $li_info->id = $child_page->id;
                $li_info->link = $child_page->link;
                $li_info->number_of_forms = $child_page->number_of_forms;
                $li_info->number_of_galleries = $child_page->number_of_galleries;

                $page_lang = PageLang::preload($child_page->id);
                $li_info->name = $page_lang->name;
                $page_url = $page_lang->url;

                $li_info->permissions['add'] = Auth::action('pages.add', ['page_id' => $child_page->id]);
                $li_info->permissions['edit'] = Auth::action('pages.edit', ['page_id' => $child_page->id]);
                $li_info->permissions['delete'] = Auth::action('pages.delete', ['page_id' => $child_page->id]);
                $li_info->permissions['group'] = Auth::action('groups.pages', ['page_id' => $child_page->id]);
                $li_info->permissions['galleries'] = Auth::action('gallery.edit', ['page_id' => $child_page->id]);
                $li_info->permissions['forms'] = Auth::action('forms.submissions', ['page_id' => $child_page->id]);
                $li_info->permissions['blog'] = Auth::action('system.wp_login');

                if ($page_url == '/' && $child_page->link == 0) {
                    $li_info->url = '';
                    $li_info->permissions['add'] = false;
                    $li_info->permissions['delete'] = false;
                } else {
                    $li_info->url = $cat_url . '/' . $page_url;
                }
                if ($child_page->group_container > 0) {
                    $li_info->type = 'type_group';
                    $li_info->group = $child_page->group_container;
                    $li_info->leaf = '';
                } else {
                    $li_info->group = null;
                    $li_info->leaf = $this->_list_pages($child_page->id, $level + 1, $li_info->url);
                    if ($li_info->link == 1) {
                        $li_info->url = $page_url;
                        $li_info->type = 'type_link';
                    } else {
                        $li_info->type = 'type_normal';
                    }
                }
                if (trim($li_info->url, '/') != '' && trim($li_info->url, '/') == trim(config('coaster::blog.url'), '/')) {
                    $li_info->blog = URL::to(config('coaster::admin.url') . '/system/wp-login');
                } else {
                    $li_info->blog = '';
                }
                if (!$child_page->is_live()) {
                    $li_info->type = 'type_hidden';
                }
                $pages_li .= View::make('coaster::partials.pages.li', array('page' => $li_info))->render();
            }
            return View::make('coaster::partials.pages.ol', array('pages_li' => $pages_li, 'level' => $level))->render();
        }
        return null;
    }

    private function _save_page_info($page_id = 0)
    {
        $input = Request::all();
        $canPublish = (config('coaster::admin.publishing') > 0 && Auth::action('pages.version-publish', ['page_id' => $page_id])) || config('coaster::admin.publishing') == 0;

        /*
         * Load data from request / db
         */
        if (!empty($page_id)) {
            $page = Page::find($page_id);
            if (empty($page)) {
                throw new \Exception('page not found');
            }
            foreach ($page->getAttributes() as $attribute => $value) {
                if (!in_array($attribute, ['updated_at', 'created_at']) && !isset($input['page_info'][$attribute])) {
                    $input['page_info'][$attribute] = $page->$attribute;
                }
            }
            $page_info = $input['page_info'];
            $page_lang = PageLang::preload($page_id);
            foreach ($page_lang->getAttributes() as $attribute => $value) {
                if (!in_array($attribute, ['updated_at', 'created_at']) && !isset($input['page_info_lang'][$attribute])) {
                    $input['page_info_lang'][$attribute] = $page_lang->$attribute;
                }
            }
            $page_info_lang = $input['page_info_lang'];
        } else {
            $page = new Page;
            $page_lang = new PageLang;
            $page_info = array_merge([
                'template' => 0,
                'parent' => 0,
                'child_template' => 0,
                'order' => 0,
                'group_container' => 0,
                'in_group' => 0,
                'link' => 0,
                'live' => 0,
                'sitemap' => 1,
                'live_start'=> null,
                'live_end' => null
            ], $input['page_info']);
            $page_info_lang = array_merge([
                'url' => '/',
                'name' => ''
            ], $input['page_info_lang']);
        }
        $page_info_other = !empty($input['page_info_other'])?$input['page_info_other']:[];

        /*
         * Save Page
         */
        if ($page_info['parent'] > 0 || $page_info['in_group'] > 0) {
            if ($page_info['in_group']) {
                $group = PageGroup::find($page_info['in_group']);
                if (!empty($group)) {
                    $parentId = $group->default_parent;
                } else {
                    $parentId = -1;
                }
            } else {
                $parentId = $page_info['parent'];
            }
            $parent = Page::find($parentId);
            if (empty($parent) && !empty($page_id)) {
                return false;
            }
        }

        if ($page_info['in_group'] > 0) {
            $page_info['parent'] = -1;
            $siblings = PageGroup::page_ids($page_info['in_group']);
            $page_info['order'] = 0;
        } else {
            $siblings = Page::child_page_ids($page_info['parent']);
            if (isset($page->order)) {
                $page_info['order'] = $page->order;
            } else {
                $page_order = Page::where('parent', '=', $page_info['parent'])->orderBy('order', 'desc')->first();
                if (!empty($page_order)) {
                    $page_info['order'] = $page_order->order + 1;
                }
            }
        }

        $versionTemplate = $page_info['template'];
        if (empty($input['publish']) && $page_id) {
            $page_info['template'] = $page->template;
        }
        if ($page_info['link'] == 1) {
            $page_info['template'] = 0;
        }

        if ($page_info['live'] == 2 && empty($page_info['live_start'])) {
            $page_info['live_start'] = date("Y-m-d H:i:s", time());
        }
        $page_info['live_start'] = Datetime::jQueryToMysql($page_info['live_start']);
        $page_info['live_end'] = Datetime::jQueryToMysql($page_info['live_end']);

        if (!$canPublish) {
            if ($page_id == 0) {
                $page_info['live'] = 0;
            } else {
                foreach ($page_info as $attribute => $value) {
                    if (!in_array($attribute, ['updated_at', 'created_at'])) {
                        $page_info[$attribute] = $page->$attribute;
                    }
                }
            }
        }

        foreach ($page_info as $attribute => $value) {
            if (!in_array($attribute, ['updated_at', 'created_at'])) {
                $page->$attribute = $page_info[$attribute];
            }
        }

        /*
         * Save Page Lang
         */
        if ($page_info_lang['name'] == '') {
            FormMessage::add('page_info_lang[name]', 'page name required');
            return false;
        }

        if ($page->link == 0) {
            $page_info_lang['url'] = strtolower(str_replace('/', '-', $page_info_lang['url']));
        }
        if (preg_match('#^[-]+$#', $page_info_lang['url'])) {
            $page_info_lang['url'] = '';
        }

        if ($page_info_lang['url'] == '' && (isset($page_info['parent']) && $page_info['parent'] == 0)) {
            $page_info_lang['url'] = '/';
        }

        if ($page_info_lang['url'] == '') {
            FormMessage::add('page_info_lang[url]', 'page url required');
            return false;
        }

        if (!empty($siblings) && $page_info['link'] == 0) {
            $same_level = PageLang::where('url', '=', $page_info_lang['url'])->where('page_id', '!=', $page_id)->whereIn('page_id', $siblings)->get();
            if (!$same_level->isEmpty()) {
                FormMessage::add('page_info_lang[url]', 'url in use by another page!');
                return false;
            }
        }

        $page->save();

        if (empty($page_lang->page_id)) {
            $page_lang->page_id = $page->id;
        }
        if ($canPublish || $page_id == 0) {
            if ($page_id == 0) {
                $page_lang->live_version = 1;
            }
            $page_lang->language_id = Language::current();
            $page_lang->url = $page_info_lang['url'];
            $page_lang->name = $page_info_lang['name'];
            $page_lang->save();
        }

        $title_block = Block::where('name', '=', config('coaster::admin.title_block'))->first();
        if (!empty($title_block)) {
            BlockManager::update_block($title_block->id, $page_lang->name, $page->id); // saves first page version
        }
        PageSearchData::update_processed_text(0, strip_tags($page_lang->name), $page->id, Language::current());

        /*
         * Save Page Version
         */
        if ($page_id != 0) {
            // save page versions template
            $page_version = PageVersion::where('page_id', '=', $page->id)->where('version_id', '=', BlockManager::$to_version)->first();
            $page_version->template = $versionTemplate;
            $page_version->save();
        } else {
            // duplicate role actions from parent page
            if ($page->parent || $page->in_group) {
                if ($page->in_group) {
                    $parent_id = PageGroup::find($page->in_group)->default_parent;
                } else {
                    $parent_id = $page->parent;
                }
                foreach (UserRole::all() as $role) {
                    $page_actions = $role->page_actions()->where('page_id', '=', $parent_id)->get();
                    if (!empty($page_actions)) {
                        foreach ($page_actions as $page_action) {
                            $role->page_actions()->attach($page->id, ['action_id' => $page_action->pivot->action_id, 'access' => $page_action->pivot->access]);
                        }
                    }
                }
            }
        }

        /*
         * Save Menu Item
         */
        if ($canPublish || $page_id == 0) {
            // set menu options
            if (Auth::action('menus')) {
                $menus = !empty($page_info_other['menus']) ? $page_info_other['menus'] : [];
                MenuItem::set_page_menus($page->id, $menus);
            }
        }

        /*
         * Save Beacons
         */
        if (Auth::action('themes.beacons-update')) {
            $existingBeacons = [];
            $setBeacons = BlockBeacon::where('page_id', '=', $page->id)->get();
            foreach ($setBeacons as $setBeacon) {
                $existingBeacons[$setBeacon->uniqueId] = $setBeacon->uniqueId;
            }
            if (!empty($existingBeacons)) {
                BlockBeacon::preload(); // check page relations (remove page id off beacons if url changed)
            }
            if (!empty($page_info_other['beacons'])) {
                foreach ($page_info_other['beacons'] as $uniqueId) {
                    if (!empty($existingBeacons[$uniqueId])) {
                        unset($existingBeacons[$uniqueId]);
                    }
                    BlockBeacon::updateUrl($uniqueId, $page->id);
                }
                foreach ($existingBeacons as $uniqueId) {
                    BlockBeacon::updateUrl($uniqueId, 0);
                }
            }
            if (!empty($input['page_info_other_exists']['beacons'])) {
                foreach ($existingBeacons as $uniqueId) {
                    BlockBeacon::updateUrl($uniqueId, 0);
                }
            }
        }

        /*
         * Log and return saved page id
         */
        if ($page_id == 0) {
            AdminLog::new_log('Added page \'' . $page_lang->name . '\' (Page ID ' . $page->id . ')');
        } else {
            AdminLog::new_log('Updated page \'' . $page_lang->name . '\' (Page ID ' . $page->id . ')');
        }
        return $page->id;
    }

    private function _load_page_data($page_id = 0, $extra_info = [])
    {
        $page_info = Request::input('page_info');
        $page_info_lang = Request::input('page_info_lang');

        $extra_info = array_merge([
            'parent' => 0,
            'version' => 0
        ], $extra_info);

        $blocks = null;
        $blocks_content = null;
        $auth = [];
        $versionData = [];
        $frontendLink = '';

        $publishingOn = (config('coaster::admin.publishing') > 0) ? true : false;
        $auth['can_publish'] = ($publishingOn && Auth::action('pages.version-publish', ['page_id' => $page_id])) || !$publishingOn;

        if (!empty($page_id)) {

            // get page data
            $page = Page::find($page_id);
            if (empty($page)) {
                return 'Page Not Found';
            }
            $page->live_start = Datetime::mysqlToJQuery($page->live_start);
            $page->live_end = Datetime::mysqlToJQuery($page->live_end);
            $group = PageGroup::find($page->in_group);
            $parent = Page::find($page->parent);

            // get page lang data
            $page_lang = PageLang::where('page_id', '=', $page_id)->where('language_id', '=', Language::current())->first();
            if (empty($page_lang)) {
                $page_lang = PageLang::where('page_id', '=', $page_id)->first();
                if (empty($page_lang)) {
                    return 'Page Lang Data Not Found';
                }
                $page_lang = $page_lang->replicate();
                $page_lang->language_id = Language::current();
                $page_lang->save();
            }
            $page_lang->url = ltrim($page_lang->url, '/');

            // get version data
            $versionData['latest'] = PageVersion::latest_version($page_id);
            $versionData['editing'] = ($extra_info['version'] == 0 || $extra_info['version'] > $versionData['latest']) ? $versionData['latest'] : $extra_info['version'];
            $versionData['live'] = $page_lang->live_version;

            // get frontend link (preview or direct link if document)
            $frontendLink = PageLang::full_url($page_id);
            if (!$page->is_live() && $page->link == 0) {
                $live_page_version = PageVersion::where('page_id', '=', $page_id)->where('version_id', '=', $versionData['live'])->first();
                if (!empty($live_page_version)) {
                    $frontendLink .= '?preview=' . $live_page_version->preview_key;
                }
            }

            // if loading previous version get version template rather than current page template
            if ($versionData['latest'] != $versionData['editing']) {
                $page_version = PageVersion::where('version_id', '=', $versionData['editing'])->where('page_id', '=', $page_id)->first();
                if (empty($page_version)) {
                    return 'version not found';
                } else {
                    $page->template = $page_version->template;
                }
            }

            // load blocks and content
            if ($page->link == 0) {
                $theme = Theme::find(config('coaster::frontend.theme'));
                if (!empty($theme)) {
                    $blocks = Template::template_blocks($theme->id, $page->template);
                } else {
                    return 'active theme not found';
                }
                BlockManager::$current_version = $versionData['editing']; // used for repeater data
                $blocks_content = PageBlock::preload_page($page_id, $versionData['editing']);
            }

            // check duplicate permission
            if (!empty($group)) {
                $duplicate_parent = $group->default_parent;
            } else {
                $duplicate_parent = $page->parent;
            }
            $auth['can_duplicate'] = Auth::action('pages.add', ['page_id' => $duplicate_parent]);

            // add required modals
            if ($publishingOn) {
                $this->layout->modals = View::make('coaster::modals.pages.publish') . View::make('coaster::modals.pages.request_publish') . View::make('coaster::modals.pages.rename_version');
            }

        } else {

            // set page data
            $page = new Page;
            $page->in_group = 0;
            $page->parent = 0;
            $page->group_container = 0;
            if ($parent = Page::find($extra_info['parent'])) {
                if ($parent->group_container) {
                    $page->parent = -1;
                    $page->in_group = $parent->group_container;
                } else {
                    $page->parent = $extra_info['parent'];
                    $page->template = $parent->child_template;
                }
            }
            $group = PageGroup::find($page->in_group);
            if (!empty($group)) {
                $page->template = $group->default_template;
            }
            $page->link = 0;
            if (!$auth['can_publish']) {
                $page->live = 0;
            } else {
                $page->live = 1;
            }
            $page->sitemap = 1;

            // set page lang data
            $page_lang = new PageLang;
            $page_lang->name = '';
            $page_lang->url = '';

        }

        // set group details if a group page
        if (!empty($group)) {
            $item_name = $group->item_name;
            $group_name = $group->name;
        } else {
            $item_name = 'Page';
            $group_name = '';
        }

        // load child template from parent page template
        if (empty($page->template) && !empty($parent)) {
            $parent_template = Template::find($parent->template);
            if (!empty($parent_template)) {
                $page->template = $parent_template->child_template;
            }
        }

        // get default template if not still note set above
        if (empty($page->template)) {
            $page->template = config('coaster::admin.default_template');
        }

        // load submitted data
        if (!empty($page_info)) {
            foreach ($page_info as $attribute => $value) {
                $page->$attribute = $page_info[$attribute];
            }
        }
        if (!empty($page_info_lang)) {
            foreach ($page_info_lang as $attribute => $value) {
                $page_lang->$attribute = $page_info_lang[$attribute];
            }
        }

        $tab_data = BlockManager::tab_contents(
            $blocks,
            $blocks_content,
            $item_name,
            $page,
            $page_lang
        );

        if ($page_id > 0) {
            return View::make('coaster::pages.pages.edit', [
                'page' => $page,
                'page_lang' => $page_lang,
                'item_name' => $item_name,
                'group_name' => $group_name,
                'publishingOn' => $publishingOn,
                'tab' => $tab_data,
                'frontendLink' => $frontendLink,
                'version' => $versionData,
                'auth' => $auth
            ]);
        } else {
            return View::make('coaster::pages.pages.add', [
                'page' => $page,
                'item_name' => $item_name,
                'group_name' => $group_name,
                'tab' => $tab_data
            ]);
        }
    }

}