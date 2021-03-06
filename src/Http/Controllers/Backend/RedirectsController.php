<?php namespace CoasterCms\Http\Controllers\Backend;

use CoasterCms\Models\AdminLog;
use CoasterCms\Models\PageRedirect;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

class RedirectsController extends _Base
{

    public function get_index()
    {
        $order = Request::input('order');
        $pageRedirect = new PageRedirect;
        if (!empty($order) && Schema::hasColumn($pageRedirect->getTable(), $order)) {
            $redirects = PageRedirect::orderBy($order)->get();
        } else {
            $redirects = PageRedirect::all();
        }
        $this->layout->content = View::make('coaster::pages.redirects', array('redirects' => $redirects, 'can_edit' => Auth::action('redirects.edit')));
    }

    public function post_index()
    {
        if (Auth::action('redirects.edit')) {
            // update blocks
            $new_redirects = array();
            $to_delete = array();
            $current_redirects = PageRedirect::all();
            foreach ($current_redirects as $current_redirect) {
                $new_redirects[$current_redirect->redirect] = $current_redirect;
                $to_delete[$current_redirect->redirect] = $current_redirect;
            }
            $redirects = Request::input('redirect');
            if (!empty($redirects)) {
                foreach ($redirects as $redirect) {
                    if (!empty($redirect['from'])) {
                        unset($to_delete[$redirect['from']]);
                        $redirect['from'] = urldecode($redirect['from']);
                        unset($to_delete[$redirect['from']]);
                        if (empty($new_redirects[$redirect['from']])) {
                            $new_redirects[$redirect['from']] = new PageRedirect;
                        }
                        $new_redirects[$redirect['from']]->redirect = $redirect['from'];
                        $new_redirects[$redirect['from']]->to = !empty($redirect['to']) ? $redirect['to'] : '/';
                        $new_redirects[$redirect['from']]->force = !empty($redirect['force']) ? 1 : 0;
                        $new_redirects[$redirect['from']]->save();
                    }
                }
            }
            foreach ($to_delete as $delete) {
                $delete->delete();
            }

            AdminLog::new_log('Mass redirects update');
            $alert = new \stdClass;
            $alert->type = 'success';
            $alert->header = 'Redirects Updated';
            $alert->content = '';
            $this->layout->alert = $alert;
        }
        $this->get_index();
    }

    public function getUrlDecode()
    {
        $redirects = PageRedirect::all();
        foreach ($redirects as $redirect) {
            $redirect->redirect = urldecode($redirect->redirect);
            $redirect->save();
        }
        $this->layout->content = 'Redirect to URL\'s decoded';
    }

    public function postEdit()
    {
        if (Auth::action('redirects.edit')) {
            $redirect = PageRedirect::find(Request::input('delete_id'));
            if (!empty($redirect)) {
                $redirect->delete();
                AdminLog::new_log('Redirect url \'' . $redirect->redirect . '\' removed');
                return 1;
            }
        }
        return 0;
    }

    public function get_import()
    {
        PageRedirect::import();
        return 'Import Run';
    }

}