<?php

namespace App\Http\Controllers;

use DirectAdmin\DirectAdmin;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use App\DAAccount;
use App\DAServer;

class AccountController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Closes a user's tab.
     *
     * @return \Illuminate\Contracts\Support\Renderable
    **/
    public function finishSession() {
        return view('panel.closetab');
    }

    /**
     * Obtains a list of email accounts.
     */
     private function list_email_accounts($accountID) {
        if (DAAccount::where('id', $accountID)->where('ownerID', \Auth::user()->id)->count() == 0) {
            $request->session()->flash('message.level', 'error');
            $request->session()->flash('message.content', 'You do not have sufficient permissions to manage this service.');
            return array();
        }

        $account = DAAccount::where('id', $accountID)->first();
        $serverObject = DAServer::where('id', $account->serverID)->first();
        $domains = $this->domainArray($accountID);

        $da = new DirectAdmin();
        $da->connect("ssl://" . $serverObject->hostname, 2222);
        $da->set_login($serverObject->username . "|" . $account->username, $serverObject->apikey);
        $email_accounts = [];

        foreach ($domains as $domain) {
            $res = NULL;
            $da->query('/CMD_API_POP?action=list&domain=' . $domain);
            parse_str(urldecode($da->fetch_body()), $res);
            if (isset($res["list"])) {
                foreach ($res["list"] as $prefix) {
                    array_push($email_accounts, $prefix . "@" . $domain);
                }
            }
        }

        return $email_accounts;
     }

    /** 
     * Returns array of domains in user account.
     *
    **/
    private function domainArray($accountID) {
        if (DAAccount::where('id', $accountID)->where('ownerID', \Auth::user()->id)->count() == 0) {
            $request->session()->flash('message.level', 'error');
            $request->session()->flash('message.content', 'You do not have sufficient permissions to manage this service.');
            return array();
        }
        $account = DAAccount::where('id', $accountID)->first();
        $serverObject = DAServer::where('id', $account->serverID)->first();

        $da = new DirectAdmin();
        $da->connect("ssl://" . $serverObject->hostname, 2222);
        $da->set_login($serverObject->username . "|" . $account->username, $serverObject->apikey);

        $da->query('/CMD_API_SHOW_DOMAINS');
        parse_str(urldecode($da->fetch_body()), $res);
        if (empty($res["list"])) return array();
        return $res["list"];
    } 

    /**
     * Obtains email accounts via API request.
     *
     * @return \Illuminate\Contracts\Support\Renderable
    **/
    public function getPOP($accountID, Request $request) {
        if (DAAccount::where('id', $accountID)->where('ownerID', \Auth::user()->id)->count() == 0) {
            $request->session()->flash('message.level', 'error');
            $request->session()->flash('message.content', 'You do not have sufficient permissions to manage this service.');
            return finishSession();
        }

        $account = DAAccount::where('id', $accountID)->first();
        $serverObject = DAServer::where('id', $account->serverID)->first();
        $email_accounts = $this->list_email_accounts($accountID);

        return json_encode(array("status" => "success", "accounts" => json_encode($email_accounts)));
    }

    /**
     * Allows users to find their email accounts.
     *
     * @return \Illuminate\Contracts\Support\Renderable
    **/
    public function popIndex($accountID, Request $request) {
        if (DAAccount::where('id', $accountID)->where('ownerID', \Auth::user()->id)->count() == 0) {
            $request->session()->flash('message.level', 'error');
            $request->session()->flash('message.content', 'You do not have sufficient permissions to manage this service.');
            return finishSession();
        }

        $email_accounts = $this->list_email_accounts($accountID);
        $total = count($email_accounts);
        $per_page = 3;
        $current_page = $request->input("page") ?? 1;
        $starting_point = ($current_page * $per_page) - $per_page;

        $email_arr = array_slice($email_accounts, $starting_point, $per_page, true);

        $paginator = new LengthAwarePaginator($email_arr, $total, $per_page, $current_page, []);
        $paginator->withPath(route('accounts.domain.pop', ['accountID' => $accountID]));

        return view('panel.pop')->with('accountID', $accountID)->with('email_accounts', $paginator)->with('domains', $this->domainArray($accountID));
    }

    /**
     * Deletes a virtual POP account in a particular service.
     *
     * Return code:
     * -1: insufficient permissions
     * -2: domain invalid
     * -3: domain does not exist
     * -4: email account does not exist
     * -5: email account invalid
     *  0: success
     * @return \Illuminate\Contracts\Support\Renderable
    **/
    public function deletePOP($accountID, Request $request) {
        if (DAAccount::where('id', $accountID)->where('ownerID', \Auth::user()->id)->count() == 0) {
            $request->session()->flash('message.level', 'error');
            $request->session()->flash('message.content', 'You do not have sufficient permissions to manage this service.');
            return '-1';
        }
        if (!filter_var($request->input('address'), FILTER_VALIDATE_EMAIL)) return '-5';
        
        $parts = explode('@', $request->input('address'));
        $domain = $parts[count($parts) - 1];
        $address = $parts[0];

        if (count($parts) != 2) return '-2';
        if (empty($domain)) return '-2';
        if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) return '-2';
        $current_domains = $this->domainArray($accountID);
        if (!in_array($domain, $current_domains)) return '-3';
        if (!in_array($request->input('address'), $this->list_email_accounts($accountID))) return '-4';

        $account = DAAccount::where('id', $accountID)->first();
        $serverObject = DAServer::where('id', $account->serverID)->first();

        $da = new DirectAdmin();
        $da->connect("ssl://" . $serverObject->hostname, 2222);
        $da->set_login($serverObject->username . "|" . $account->username, $serverObject->apikey);

        $da->query('/CMD_API_POP', http_build_query(array(
            "action" => "delete",
            "domain" => $domain,
            "user" => $address
        )));
        parse_str(urldecode($da->fetch_body()), $res);
        if ($res["error"] == "1") return $res["details"];
        return '0';
    }

    /**
     * Creates a new POP account in a particular service.
     *
     * Return code:
     * -1: insufficient permissions
     * -2: domain invalid
     * -3: address exists
     * -4: address invalid
     * -5: directadmin error
     *  0: success
     * @return \Illuminate\Contracts\Support\Renderable
    **/
    public function createPOP($accountID, Request $request) {
        if (DAAccount::where('id', $accountID)->where('ownerID', \Auth::user()->id)->count() == 0) {
            $request->session()->flash('message.level', 'error');
            $request->session()->flash('message.content', 'You do not have sufficient permissions to manage this service.');
            return '-1';
        }
        if (empty($request->input('domain'))) return '-2';
        if (!filter_var($request->input('domain'), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) return '-2';
        if (!in_array($request->input('domain'), $this->domainArray($accountID))) return '-2';
        if (in_array($request->input('address') . "@" . $request->input('domain'), $this->list_email_accounts($accountID))) return '-3';
        if (preg_match('/[\'^£$%&*()}{@#~?><>,|=+¬]/', $request->input('address'))) return '-4';

        $account = DAAccount::where('id', $accountID)->first();
        $serverObject = DAServer::where('id', $account->serverID)->first();

        $da = new DirectAdmin();
        $da->connect("ssl://" . $serverObject->hostname, 2222);
        $da->set_login($serverObject->username . "|" . $account->username, $serverObject->apikey);
        $temp_password = bin2hex(random_bytes(7));

        $da->query('/CMD_API_POP', http_build_query(array(
            "action" => "create",
            "domain" => $request->input('domain'),
            "user" => $request->input('address'),
            "passwd" => $temp_password,
            "passwd2" => $temp_password,
            "quota" => 0
        )));

        parse_str(urldecode($da->fetch_body()), $res);
        if ($res["error"] == "1") return '-5';
        return $temp_password;
    }

        /**
     * Reset a virtual POP account's password in a particular service.
     *
     * Return code:
     * -1: insufficient permissions
     * -2: domain invalid
     * -3: domain does not exist
     * -4: email account does not exist
     * -5: email account invalid
     * -6: DA error
     *  0: success
     * @return \Illuminate\Contracts\Support\Renderable
    **/
    public function resetPOP($accountID, Request $request) {
        if (DAAccount::where('id', $accountID)->where('ownerID', \Auth::user()->id)->count() == 0) {
            $request->session()->flash('message.level', 'error');
            $request->session()->flash('message.content', 'You do not have sufficient permissions to manage this service.');
            return '-1';
        }
        if (!filter_var($request->input('address'), FILTER_VALIDATE_EMAIL)) return '-5';
        
        $parts = explode('@', $request->input('address'));
        $domain = $parts[count($parts) - 1];
        $address = $parts[0];

        if (count($parts) != 2) return '-2';
        if (empty($domain)) return '-2';
        if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) return '-2';
        $current_domains = $this->domainArray($accountID);
        if (!in_array($domain, $current_domains)) return '-3';
        if (!in_array($request->input('address'), $this->list_email_accounts($accountID))) return '-4';

        $account = DAAccount::where('id', $accountID)->first();
        $serverObject = DAServer::where('id', $account->serverID)->first();

        $da = new DirectAdmin();
        $da->connect("ssl://" . $serverObject->hostname, 2222);
        $da->set_login($serverObject->username . "|" . $account->username, $serverObject->apikey);
        $temp_password = bin2hex(random_bytes(7));

        $da->query('/CMD_API_POP', http_build_query(array(
            "action" => "modify",
            "domain" => $domain,
            "user" => $address,
            "passwd" => $temp_password,
            "passwd2" => $temp_password,
            "quota" => 0
        )));

        parse_str(urldecode($da->fetch_body()), $res);
        if ($res["error"] == "1") return -6;
        return $temp_password;
    }

    /**
     * Allows users to find their SPF records.
     *
     * @return \Illuminate\Contracts\Support\Renderable
    **/
    public function spfIndex($accountID, Request $request) {
        if (DAAccount::where('id', $accountID)->where('ownerID', \Auth::user()->id)->count() == 0) {
            $request->session()->flash('message.level', 'error');
            $request->session()->flash('message.content', 'You do not have sufficient permissions to manage this service.');
            return finishSession();
        }

        $domains = $this->domainArray($accountID); 

        return view('panel.spf')->with('domains', $domains)->with('accountID', $accountID);
    }

    /**
     * Allows users to find their DKIM keys.
     *
     * @return \Illuminate\Contracts\Support\Renderable
    **/
    public function dkimIndex($accountID, Request $request) {
        if (DAAccount::where('id', $accountID)->where('ownerID', \Auth::user()->id)->count() == 0) {
            $request->session()->flash('message.level', 'error');
            $request->session()->flash('message.content', 'You do not have sufficient permissions to manage this service.');
            return finishSession();
        }

        $domains = $this->domainArray($accountID); 

        return view('panel.dkim')->with('domains', $domains)->with('accountID', $accountID);
    }

    /**
     * Allows users to find their DKIM keys.
     *
     * @return \Illuminate\Contracts\Support\Renderable
    **/
    public function getDkim($accountID, Request $request) {
        if (DAAccount::where('id', $accountID)->where('ownerID', \Auth::user()->id)->count() == 0) {
            $request->session()->flash('message.level', 'error');
            $request->session()->flash('message.content', 'You do not have sufficient permissions to manage this service.');
            return finishSession();
        }

        if (empty($request->input("domain"))) return json_encode(array("status" => "error", "reason" => "empty_domain"));

        $domains = $this->domainArray($accountID); 

        if (!in_array($request->input("domain"), $domains)) return json_encode(array("status" => "error", "reason" => "wrong_owner"));

        $account = DAAccount::where('id', $accountID)->first();
        $serverObject = DAServer::where('id', $account->serverID)->first();

        $da = new DirectAdmin();
        $da->connect("ssl://" . $serverObject->hostname, 2222);
        $da->set_login($serverObject->username . "|" . $account->username, $serverObject->apikey);

        $da->query('/CMD_API_DNS_CONTROL?domain=' . $request->input("domain") . '&info=no&urlencoded=yes');
        $domain_key = str_replace('"', '', strstr(strstr(substr(explode("\n", urldecode($da->fetch_body()))[5], 4), "domainkey="), '"'));

        return json_encode(array("status" => "success", "key" => $domain_key));
    }

    /**
     * Allows users to find their DKIM keys.
     *
     * @return \Illuminate\Contracts\Support\Renderable
    **/
    public function getSPF($accountID, Request $request) {
        if (DAAccount::where('id', $accountID)->where('ownerID', \Auth::user()->id)->count() == 0) {
            $request->session()->flash('message.level', 'error');
            $request->session()->flash('message.content', 'You do not have sufficient permissions to manage this service.');
            return finishSession();
        }

        if (empty($request->input("domain"))) return json_encode(array("status" => "error", "reason" => "empty_domain"));

        $domains = $this->domainArray($accountID); 

        if (!in_array($request->input("domain"), $domains)) return json_encode(array("status" => "error", "reason" => "wrong_owner"));

        $account = DAAccount::where('id', $accountID)->first();
        $serverObject = DAServer::where('id', $account->serverID)->first();

        $spf = env('SPF_STRING', 'v=spf1 include:mxlogin.com -all');

        return json_encode(array("status" => "success", "key" => $spf));
    }


    /**
     * List a user's domains.
     *
     * @return \Illuminate\Contracts\Support\Renderable
    **/
    public function domainIndex($accountID, Request $request) {
        if (DAAccount::where('id', $accountID)->where('ownerID', \Auth::user()->id)->count() == 0) {
            $request->session()->flash('message.level', 'error');
            $request->session()->flash('message.content', 'You do not have sufficient permissions to manage this service.');
            return finishSession();
        }

        $domains = $this->domainArray($accountID); 

        $total = count($domains);
        $per_page = 3;
        $current_page = $request->input("page") ?? 1;
        $starting_point = ($current_page * $per_page) - $per_page;

        $domain_arr = array_slice($domains, $starting_point, $per_page, true);

        $paginator = new LengthAwarePaginator($domain_arr, $total, $per_page, $current_page, []);
        $paginator->withPath(route('accounts.domains', ['accountID' => $accountID]));

        return view('panel.domains')->with('domains', $paginator)->with('accountID', $accountID);
    }

    /**
     * Creates a new domain in a particular service.
     *
     * Return code:
     * -1: insufficient permissions
     * -2: domain invalid
     * -3: domain exists
     * -4: directadmin error
     *  0: success
     * @return \Illuminate\Contracts\Support\Renderable
    **/
    public function domainCreate($accountID, Request $request) {
        if (DAAccount::where('id', $accountID)->where('ownerID', \Auth::user()->id)->count() == 0) {
            $request->session()->flash('message.level', 'error');
            $request->session()->flash('message.content', 'You do not have sufficient permissions to manage this service.');
            return '-1';
        }
        if (empty($request->input('domain'))) return '-2';
        if (!filter_var($request->input('domain'), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) return '-2';
        $current_domains = $this->domainArray($accountID);
        if (in_array($request->input('domain'), $current_domains)) return '-3';

        $account = DAAccount::where('id', $accountID)->first();
        $serverObject = DAServer::where('id', $account->serverID)->first();

        $da = new DirectAdmin();
        $da->connect("ssl://" . $serverObject->hostname, 2222);
        $da->set_login($serverObject->username . "|" . $account->username, $serverObject->apikey);

        $da->query('/CMD_API_DOMAIN', http_build_query(array(
            "action" => "create",
            "domain" => $request->input('domain')
        )));
        parse_str(urldecode($da->fetch_body()), $res);
        if ($res["error"] == "1") return $res["details"];
        return '0';
    }

    /**
     * Deletes a domain in a particular service.
     *
     * Return code:
     * -1: insufficient permissions
     * -2: domain invalid
     * -3: domain does not exist
     * -4: directadmin error
     * -5: last domain
     *  0: success
     * @return \Illuminate\Contracts\Support\Renderable
    **/
    public function domainDelete($accountID, Request $request) {
        if (DAAccount::where('id', $accountID)->where('ownerID', \Auth::user()->id)->count() == 0) {
            $request->session()->flash('message.level', 'error');
            $request->session()->flash('message.content', 'You do not have sufficient permissions to manage this service.');
            return '-1';
        }
        if (empty($request->input('domain'))) return '-2';
        if (!filter_var($request->input('domain'), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) return '-2';
        $current_domains = $this->domainArray($accountID);
        if (count($current_domains) == 1) return '-5';
        if (!in_array($request->input('domain'), $current_domains)) return '-3';

        $account = DAAccount::where('id', $accountID)->first();
        $serverObject = DAServer::where('id', $account->serverID)->first();

        $da = new DirectAdmin();
        $da->connect("ssl://" . $serverObject->hostname, 2222);
        $da->set_login($serverObject->username . "|" . $account->username, $serverObject->apikey);

        $da->query('/CMD_API_DOMAIN', http_build_query(array(
            "delete" => "delete",
            "confirmed" => "delete",
            "select0" => $request->input('domain')
        )));
        parse_str(urldecode($da->fetch_body()), $res);
        if ($res["error"] == "1") return $res["details"];
        return '0';
    }

    /**
     * Perform a SSO request to the email address requested, provided the user owns the email address
     * @return \Illuminate\Contracts\Support\Renderable
    */

    public function email_sso($accountID, Request $request) {
        if (DAAccount::where('id', $accountID)->where('ownerID', \Auth::user()->id)->count() == 0) {
            $request->session()->flash('message.level', 'error');
            $request->session()->flash('message.content', 'You do not have sufficient permissions to manage this service.');
            return redirect(route('accounts.list'));
        }
        $account = DAAccount::where('id', $accountID)->first();
        $serverObject = DAServer::where('id', $account->serverID)->first();

        $da = new DirectAdmin();
        $da->connect("ssl://" . $serverObject->hostname, 2222);
        $da->set_login($serverObject->username . "|" . $account->username, $serverObject->apikey);
        $da->set_method('POST');
        $new_key = substr(md5(mt_rand(0, mt_getrandmax()) / mt_getrandmax()), 0, 20);
        $parameters = [
            "action" => "create",
            "keyname" => substr(md5(mt_rand(0, mt_getrandmax()) / mt_getrandmax()), 0, 10), 
            "key" => $new_key, 
            "key2" => $new_key,
            "never_expires" => "no",
            "expiry_timestamp" => time() + 3600,
            "max_uses" => "0",
            "clear_key" => "yes",
            "allow_htm" => "yes",
            "passwd" => $serverObject->apikey,
            "select_deny0" => "CMD_LOGIN_KEYS",
            "ips" => $_SERVER['REMOTE_ADDR']
        ];

        $query = $da->query('/CMD_API_LOGIN_KEYS', http_build_query($parameters));
        $da->fetch_body();
        return view('panel.sso')->with('data', array($account->username, $new_key, $request->input('email'), $serverObject->hostname));
    }

    /**
     * Gets usage information.
     */
    private function get_usage($account, $server) {
        $server = DAServer::where('id', $account->serverID)->first();

        $da = new DirectAdmin();
        $da->connect("ssl://" . $server->hostname, 2222);
        $da->set_login($server->username, $server->apikey);
        $query = $da->query('/CMD_API_SHOW_USER_USAGE', http_build_query(array("user" => $account->username)));
        $usage = $da->fetch_body();
        $query = $da->query('/CMD_API_SHOW_USER_CONFIG', http_build_query(array("user" => $account->username)));
        $limits = $da->fetch_body();

        parse_str(urldecode($usage), $usage_arr);
        parse_str(urldecode($limits), $limits_arr);
        $usage_percentages = array();
        $usage_percentages["disk"] = ($limits_arr["quota"] == 0) ? 0 : round(($usage_arr["quota"] / $limits_arr["quota"]) * 10000) / 100;
        $usage_percentages["forwarders"] = ($limits_arr["nemailf"] == 0) ? 0 : round(($usage_arr["nemailf"] / $limits_arr["nemailf"]) * 10000) / 100;
        $usage_percentages["accounts"] = ($limits_arr["nemails"] == 0) ? 0 : round(($usage_arr["nemails"] / $limits_arr["nemails"]) * 10000) / 100;
        $usage_percentages["domains"] = ($limits_arr["vdomains"] == 0) ? 0 : round(($usage_arr["vdomains"] / $limits_arr["vdomains"]) * 10000) / 100;
        $usage_percentages["subdomains"] = ($limits_arr["nsubdomains"] == 0) ? 0 : round(($usage_arr["nsubdomains"] / $limits_arr["nsubdomains"]) * 10000) / 100;
    
        return [$usage_percentages, $usage_arr, $limits_arr];
    }

    /**
     * Show the account management page for a DirectAdmin-connected account.
     *
     * @return \Illuminate\Contracts\Support\Renderable
    */
    public function manage($accountID, Request $request) {
        if (DAAccount::where('id', $accountID)->where('ownerID', \Auth::user()->id)->count() == 0) {
            $request->session()->flash('message.level', 'error');
            $request->session()->flash('message.content', 'You do not have sufficient permissions to manage this service.');
            return redirect(route('accounts.list'));
        }
        $account = DAAccount::where('id', $accountID)->first();
        $serverObject = DAServer::where('id', $account->serverID)->first();

        $usage_data = $this->get_usage($account, $serverObject);
        $usage_percentages = $usage_data[0];
        $usage_arr = $usage_data[1];
        $limits_arr = $usage_data[2];

        $da = new DirectAdmin();
        $da->connect("ssl://" . $serverObject->hostname, 2222);
        $da->set_login($serverObject->username . "|" . $account->username, $serverObject->apikey);

        $query = $da->query('/CMD_API_SHOW_DOMAINS');
        parse_str(urldecode($da->fetch_body()), $domains);

        $email_accounts = array();

        return view('panel.manage')->with('account', array($account, $usage_arr, $limits_arr, $usage_percentages, $email_accounts, $serverObject)); 
    }

    /**
     * API command to obtain usage details
     */
    public function api_get_usage($accountID) {
        if (DAAccount::where('id', $accountID)->where('ownerID', \Auth::user()->id)->count() == 0) {
            return json_encode(array("status" => "error", "desc" => "Invalid service ID."));
        }

        $account = DAAccount::where('id', $accountID)->first();
        $serverObject = DAServer::where('id', $account->serverID)->first();

        $usage_data = $this->get_usage($account, $serverObject);
        $usage_percentages = $usage_data[0];
        $usage_arr = $usage_data[1];
        $limits_arr = $usage_data[2];

        return json_encode(array("usage" => $usage_data[1], "limits" => $usage_data[2], "percentages" => $usage_data[0]));
    }

    /**
     * List the user's DirectAdmin-connected accounts.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {
        $accounts = DAAccount::where('ownerID', \Auth::user()->id)->paginate(5);
        $servers = [];
        foreach ($accounts as $account) {
            if (!isset($servers[md5($account->serverID)])) {
                $serverObject = DAServer::where('id', $account->serverID)->first();
                $da = new DirectAdmin();
                $da->connect("ssl://" . $serverObject->hostname, 2222);
                $da->set_login($serverObject->username, $serverObject->apikey);
                $query = $da->query('/CMD_API_SHOW_USER_USAGE', http_build_query(array("user" => $account->username)));
                $usage = $da->fetch_body();
                $query = $da->query('/CMD_API_SHOW_USER_CONFIG', http_build_query(array("user" => $account->username)));
                $limits = $da->fetch_body();
                $da = NULL;
                parse_str(urldecode($usage), $usage_arr);
                parse_str(urldecode($limits), $limits_arr);
                $servers[md5($account->serverID)] = array($serverObject, $usage_arr, $limits_arr); 
            }
        }
        
        return view('panel.list')->with('accounts', $accounts)->with('servers', $servers);
    }
}
