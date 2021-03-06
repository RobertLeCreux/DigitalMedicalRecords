<?php

/**
 * General methods. Added in 2.3.0 - will replace the older genera.php file.
 *
 * @copyright Benjamin Keen 2017
 * @author Benjamin Keen <ben.keen@gmail.com>
 * @package 2-3-x
 * @subpackage Database
 */


// -------------------------------------------------------------------------------------------------

namespace FormTools;

use PDO;
use PDOException;


class General
{
    /**
     * Helper function that's used on Step 2 to confirm that the Core Field Types module folder exists.
     *
     * @param string $module_folder
     */
    public static function checkModuleAvailable($module_folder)
    {
        return is_dir(realpath(__DIR__ . "/../../modules/$module_folder"));
    }


    /**
     * Gets a list of known Form Tools tables in a database.
     * @return array
     */
    public static function getExistingTables(Database $db, array $all_tables, $table_prefix)
    {
        $db->query("SHOW TABLES");

        $prefixed_tables = array();
        foreach ($all_tables as $table_name) {
            $prefixed_tables[] = $table_prefix . $table_name;
        }

        $existing_tables = array();
        foreach ($db->fetchAll(PDO::FETCH_NUM) as $row) {
            $curr_table = $row[0];
            if (in_array($curr_table, $prefixed_tables)) {
                $existing_tables[] = $curr_table;
            }
        }

        return $existing_tables;
    }


    /**
     * Helper method to convert an array to rows of HTML in bullet points.
     * @return array
     */
    public static function getErrorListHTML(array $errors) {
        array_walk($errors, create_function('&$el','$el = "&bull;&nbsp; " . $el;'));
        return join("<br />", $errors);
    }

    /**
     * Returns a date in Y-m-d H:i:s format, generally used for inserting into a MySQL
     * datetime field.
     *
     * @param string $timestamp an optional Unix timestamp to convert to a datetime
     * @return string the current datetime in string format
     * */
    public static function getCurrentDatetime($timestamp = "")
    {
        if (!empty($timestamp)) {
            $datetime = date("Y-m-d H:i:s", $timestamp);
        } else {
            $datetime = date("Y-m-d H:i:s");
        }
        return $datetime;
    }


    /**
     * Checks to see if a database table exists. Handy for modules to check to see if they've been installed
     * or not.
     *
     * @return boolean
     */
    public static function checkDbTableExists($table)
    {
        $db = Core::$db;
        $db_name = Core::getDbName();

        $found = false;
        $db->query("SHOW TABLES FROM $db_name");
        $db->execute();
        foreach ($db->fetchAll(PDO::FETCH_COLUMN) as $curr_table) {
            if ($curr_table == $table) {
                $found = true;
                break;
            }
        }
        return $found;
    }


    /**
     * Helper function to convert a MySQL datetime to a unix timestamp.
     *
     * @param string $datetime
     * @return string
     */
    public static function convertDatetimeToTimestamp($datetime)
    {
        list($date, $time) = explode(" ", $datetime);
        list($year, $month, $day) = explode("-", $date);
        list($hours, $minutes, $seconds) = explode(":", $time);

        return mktime($hours, $minutes, $seconds, $month, $day, $year);
    }


    /**
     * Helps manage long strings by adding either an ellipsis or inserts a inserts a <br /> at the position specified,
     * and returns the result.
     *
     * @param string $str The string to manipulate.
     * @param string $length The max length of the string / place to insert <br />
     * @param string $flag "ellipsis" / "page_break"
     * @return string The modified string.
     */
    public static function trimString($str, $length, $flag = "ellipsis")
    {
        if (mb_strlen($str) < $length) {
            $new_string = $str;
        } else {
            if ($flag == "ellipsis") {
                $new_string = mb_substr($str, 0, $length) . "...";
            } else {
                $parts = General::mbStrSplit($str, $length);
                $new_string = join("<br />", $parts);
            }
        }

        return $new_string;
    }


    /**
     * Checks that the currently logged in client is permitted to view a particular form View. This is called
     * on the form submissions and edit submission pages, to ensure the client isn't trying to look at something
     * they shouldn't. Any time it fails, it logs them out with a message informing them that they're not allowed
     * to access that page. (FYI, it's possible that this scenario could happen honestly: e.g. if the administrator
     * creates a client menu containing links to particular forms; then accidentally assigning a client to the menu
     * that doesn't have permission to view the form).
     *
     * This relies on the "permissions" key being set by the login function: it contains the form and View IDs.
     *
     * Because of this, any time the administrator changes the permissions for a client, they'll need te re-login to
     * access that new information.
     *
     * Very daft this function doesn't return a boolean, but oh well. The fourth param was added to get around that.
     *
     * @param integer $form_id The unique form ID
     * @param integer $client_id The unique client ID
     * @param integer $view_id
     * @param boolean
     */
    public static function checkClientMayView($client_id, $form_id, $view_id, $return_boolean = false)
    {
        $permissions = Sessions::getWithFallback("permissions", array());

        extract(Hooks::processHookCalls("main", compact("client_id", "form_id", "view_id", "permissions"), array("permissions")), EXTR_OVERWRITE);

        $may_view = true;
        if (!array_key_exists($form_id, $permissions)) {
            $may_view = false;
            if (!$return_boolean) {
                Core::$user->logout("notify_invalid_permissions");
            }
        } else {
            if (!empty($view_id) && !in_array($view_id, $permissions[$form_id])) {
                $may_view = false;
                if (!$return_boolean) {
                    Core::$user->logout("notify_invalid_permissions");
                }
            }
        }

        return $may_view;
    }


    /**
     * This invaluable little function is used for storing and overwriting the contents of a single
     * form field in sessions based on a sequence of priorities.
     *
     * It assumes that a variable name can be found in GET, POST or SESSIONS (or all three). What this
     * function does is return the value stored in the most important variable (GET first, POST second,
     * SESSIONS third), and update sessions at the same time. This is extremely helpful in situations
     * where you don't want to keep having to submit the same information from page to page.
     * The third parameter sets a default value.
     *
     * @param string $field_name the field name
     * @param string $session_name the session key for this field name
     * @param string $default_value the default value for the field
     * @return string the field value
     */
    public static function loadField($field_name, $session_name, $default_value = "")
    {
        $field = $default_value;

        if (isset($_GET[$field_name])) {
            $field = $_GET[$field_name];
            Sessions::set($session_name, $field);
        } else if (isset($_POST[$field_name])) {
            $field = $_POST[$field_name];
            Sessions::set($session_name, $field);
        } else if (Sessions::exists($session_name)) {
            $field = Sessions::get($session_name);
        }

        return $field;
    }


    /**
     * Used to convert language file strings into their JS-compatible counterparts, all within an
     * "g" namespace.
     *
     * @param array keys The $LANG keys
     * @param array keys The content of $L (language file array for a specific module)
     * @param array keys The $L keys
     * @return string $js the javascript string (WITHOUT the <script> tags)
     */
    public static function generateJsMessages($keys = array(), $L = array(), $module_keys = array())
    {
        $LANG = Core::$L;

        $js_rows = array();
        if (!empty($keys)) {
            for ($i=0; $i<count($keys); $i++) {
                $key = $keys[$i];
                if (array_key_exists($key, $LANG)) {
                    $str = preg_replace("/\"/", "\\\"", $LANG[$key]);
                    $js_rows[] = "g.messages[\"$key\"] = \"$str\";";
                }
            }
        }

        if (!empty($module_keys)) {
            for ($i=0; $i<count($module_keys); $i++) {
                $key = $module_keys[$i];
                if (array_key_exists($key, $L)) {
                    $str = preg_replace("/\"/", "\\\"", $L[$key]);
                    $js_rows[] = "g.messages[\"$key\"] = \"$str\";";
                }
            }
        }
        $rows = join("\n", $js_rows);

        $js =<<< END
if (typeof g == "undefined") {
  g = {};
}
g.messages = [];
$rows
END;

        extract(Hooks::processHookCalls("end", compact("js"), array("js")), EXTR_OVERWRITE);

        return $js;
    }


    /**
     * Added in 2.1.0. The idea behind this is that every now and then, we need to display a custom message
     * in a page - e.g. after redirecting somewhere, or some unusual case. These situations are handled by passing
     * a ?message=XXX query string parameter. This function is called in the Themes::displayPage() function directly
     * so it all happens "automatically" with no additional configuration needed on each page.
     *
     * Caveats:
     * - it will override $g_success and $g_message to always output it in the page. This is good! But keep it in mind.
     * - the messages should be very simple and not contain relative links. Bear in mind the user can hack it and paste
     *   those flags onto any page.
     *
     * @param $flag
     */
    public static function displayCustomPageMessage($flag)
    {
        $LANG = Core::$L;

        $map = array(
            "no_views"                     => array(false, $LANG["notify_no_views"]),
            "notify_internal_form_created" => array(true, $LANG["notify_internal_form_created"]),
            "change_temp_password"         => array(true, $LANG["notify_change_temp_password"]),
            "new_submission"               => array(true, $LANG["notify_new_submission_created"]),
            "notify_sessions_timeout"      => array(true, $LANG["notify_sessions_timeout"]),
            "notify_no_views_assigned_to_client_form" => array(false, $LANG["notify_no_views_assigned_to_client_form"])
        );

        $found = false;
        $g_success = "";
        $g_message = "";
        if (array_key_exists($flag, $map)) {
            $found = true;
            $g_success = $map[$flag][0];
            $g_message = $map[$flag][1];
        }

        extract(Hooks::processHookCalls("end", compact("flag"), array("g_success", "g_message")), EXTR_OVERWRITE);

        return array($found, $g_success, $g_message);
    }


    /**
     * This function evaluates any string that contains Smarty content. It parses the email templates, filename
     * strings and other such functionality. It uses on the eval.tpl template, found in /global/smarty.
     *
     * @param string $placeholder_str the string containing the placeholders / Smarty logic
     * @param array $placeholders a hash of values to pass to the template. The contents of the
     *    current language file is ALWAYS sent.
     * @param string $theme
     * @return string a string containing the output of the eval()'d smarty template
     */
    public static function evalSmartyString($placeholder_str, $placeholders = array(), $theme = "", $plugin_dirs = array())
    {
        $theme = Core::$user->getTheme();

        $smarty = Templates::getBasicSmarty($theme);

        foreach ($plugin_dirs as $dir) {
            $smarty->addPluginsDir($dir);
        }

        $smarty->assign("eval_str", $placeholder_str);
        if (!empty($placeholders)) {
            while (list($key, $value) = each($placeholders)) {
                $smarty->assign($key, $value);
            }
        }

        $output = $smarty->fetch(realpath(__DIR__ . "/../smarty_plugins/eval.tpl"));

        extract(Hooks::processHookCalls("end", compact("output", "placeholder_str", "placeholders", "theme"), array("output")), EXTR_OVERWRITE);

        return $output;
    }


    /**
     * Helper function to remove all but those chars specified in the section param.
     *
     * @param string string to examine
     * @param string string of acceptable chars
     * @return string the cleaned string
     */
    public static function stripChars($str, $whitelist = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789")
    {
	    $valid_chars = preg_quote($whitelist);
	    return preg_replace("/[^$valid_chars]/", "", $str);
    }


    /**
     * Another security-related function. This returns a clean version of PHP_SELF for use in the templates. This wards
     * against URI Cross-site scripting attacks.
     *
     * @return string the cleaned $_SERVER["PHP_SELF"]
     */
    public static function getCleanPhpSelf()
    {
        return htmlspecialchars(strip_tags($_SERVER['PHP_SELF']), ENT_QUOTES);
    }


    /**
     * Currently used for encoding passwords. This will be updated in future versions for proper encryption.
     * @param $password
     */
    public static function encode($string) {
        return md5(md5($string));
    }


    /**
     * This updates the version of the API in the database. It's called on installation and whenever someone logs in.
     * It's used to keep the API version up to date in the database so that whenever the user clicks their UPGRADE
     * button, the correct API version is passed to the upgrade script to let it know if it needs to be upgraded or
     * not.
     */
    public static function updateApiVersion()
    {
        if (!Core::isAPIAvailable()) {
            return;
        }

        include_once(Core::getAPIPath());
        if (!isset($g_api_version) || empty($g_api_version)) {
            return;
        }
        Settings::set(array("api_version" => $g_api_version));
    }


    /**
     * Helper function to construct a valid URL. This will probably be improved and renamed in future.
     *
     * @param string $base_url
     * @param string $query_string
     */
    public static function constructUrl($url, $query_str = "")
    {
        $valid_url = $url;
        if (!empty($query_str)) {
            // only include the ? if it's not already there
            if (strpos($url, "?")) {
                $valid_url .= "&{$query_str}";
            } else {
                $valid_url .= "?{$query_str}";
            }
        }
        return $valid_url;
    }


    /**
     * Displays basic << 1 2 3 >> navigation for lists, each linking to the current page.
     *
     * This function has exactly the same purpose as display_page_nav, except that the pages are
     * hidden/shown with DHTML instead of separate server-side calls per page. This technique is better
     * for lists that contain a smaller number of items, e.g. the client and forms listing pages.
     *
     * ASSUMPTION: the JS counterpart function with the same function is defined in the calling page.
     * That function does all the work of hiding/showing pages, updating the "viewing X-Y"
     * text, enabling disabling the << and >> arrows, and storing the current page in sessions. This
     * function merely sets up the base HTML + JS.
     *
     * This function uses a dhtml_pagination.tpl Smarty template file, found in the current theme's root
     * folder.
     *
     * @param integer $num_results The total number of results found.
     * @param integer $num_per_page The max number of results to list per page.
     * @param integer $current_page The current page number being examined (defaults to 1).
     */
    public static function getJsPageNav($num_results, $num_per_page, $current_page = 1)
    {
        $theme = Core::$user->getTheme();
        $LANG = Core::$L;

        $smarty = Templates::getBasicSmarty($theme);
        $smarty->assign("num_results", $num_results);
        $smarty->assign("num_per_page", $num_per_page);
        $smarty->assign("current_page", $current_page);

        // find the range that's being displayed (e.g 11 to 20)
        $range_start = ($current_page - 1) * $num_per_page + 1;
        $range_end   = $range_start + $num_per_page - 1;
        $range_end   = ($range_end > $num_results) ? $num_results : $range_end;

        $smarty->assign("range_start", $range_start);
        $smarty->assign("range_end", $range_end);

        $viewing_range = "";
        if ($num_results > $num_per_page) {
            $replacement_info = array(
                "startnum" => "<span id='nav_viewing_num_start'>$range_start</span>",
                "endnum"   => "<span id='nav_viewing_num_end'>$range_end</span>"
            );
            $viewing_range = General::evalSmartyString($LANG["phrase_viewing_range"], $replacement_info);
        }
        $smarty->assign("viewing_range", $viewing_range);
        $smarty->assign("total_pages", ceil($num_results / $num_per_page));

        // now process the template and return the HTML
        return $smarty->fetch(Themes::getSmartyTemplateWithFallback($theme, "dhtml_pagination.tpl"));
    }


    /**
     * Displays basic &lt;&lt; 1 2 3 >> navigation for lists, each linking to the current page.
     *
     * This uses the pagination.tpl template, found in the theme's root folder.
     *
     * *** This function kind of sucks now... I just kept adding params and over time it's become totally daft. This
     * should be refactored to do a JS-like extend() option on the various permitted settings ***
     *
     * @param integer $num_results The total number of results found.
     * @param integer $num_per_page The max number of results to list per page.
     * @param integer $current_page The current page number being examined (defaults to 1).
     * @param string $pass_along_str The string to include in nav links.
     * @param string $page_str The string used in building the page nav to indicate the page number
     * @param string $theme the theme name
     * @param array $settings a hash with the following settings:
     *                   "show_total_results" => true/false (default: true)
     *                   "show_page_label"    => true/false (default: true)
     */
    public static function getPageNav($num_results, $num_per_page, $current_page = 1, $pass_along_str = "",
        $page_str = "page", $theme = "", $settings = array())
    {
        $LANG = Core::$L;

        $current_page = ($current_page < 1) ? 1 : $current_page;
        if (empty($theme)) {
            $theme = Sessions::get("account.theme");
        }

        $smarty = Templates::getBasicSmarty($theme);
        $smarty->assign("num_results", $num_results);
        $smarty->assign("num_per_page", $num_per_page);
        $smarty->assign("current_page", $current_page);
        $smarty->assign("page_str", $page_str);
        $smarty->assign("show_total_results", (isset($settings["show_total_results"])) ? $settings["show_total_results"] : true);
        $smarty->assign("show_page_label", (isset($settings["show_page_label"])) ? $settings["show_page_label"] : true);

        // display the total number of results found
        $range_start = ($current_page - 1) * $num_per_page + 1;
        $range_end   = $range_start + $num_per_page - 1;
        $range_end   = ($range_end > $num_results) ? $num_results : $range_end;

        $smarty->assign("range_start", $range_start);
        $smarty->assign("range_end", $range_end);

        $viewing_range = "";
        if ($num_results > $num_per_page) {
            $replacement_info = array(
                "startnum" => "<span id='nav_viewing_num_start'>$range_start</span>",
                "endnum"   => "<span id='nav_viewing_num_end'>$range_end</span>"
            );
            $viewing_range = General::evalSmartyString($LANG["phrase_viewing_range"], $replacement_info);
        }
        $total_pages = ceil($num_results / $num_per_page);
        $smarty->assign("viewing_range", $viewing_range);
        $smarty->assign("total_pages", $total_pages);
        $smarty->assign("same_page", $_SERVER["PHP_SELF"]);

        // piece together additional query string values
        $smarty->assign("query_str", !empty($pass_along_str) ? "&{$pass_along_str}" : "");

        // determine the first and last pages to show page nav links for
        $half_total_nav_pages  = floor(Core::getMaxNavPages() / 2);
        $first_page = ($current_page > $half_total_nav_pages) ? $current_page - $half_total_nav_pages : 1;
        $last_page  = (($current_page + $half_total_nav_pages) < $total_pages) ? $current_page + $half_total_nav_pages : $total_pages;

        $smarty->assign("first_page", $first_page);
        $smarty->assign("last_page", $last_page);
        $smarty->assign("include_first_page_direct_link", (($first_page != 1) ? true : false));
        $smarty->assign("include_last_page_direct_link", (($first_page != $total_pages) ? true : false));

        // now process the template and return the HTML
        return $smarty->fetch(Themes::getSmartyTemplateWithFallback($theme, "pagination.tpl"));
    }


    /**
     * For handling all server-side redirects.
     * @param $pageS
     */
    public static function redirect($page) {
        session_write_close();
        header("Location: $page");
        exit;
    }

    /**
     * Returns the maximum size of a file allowed to be uploaded according to this server's php.ini file.
     *
     * @return integer the max file size in bytes
     */
    public static function getUploadMaxFilesize()
    {
        $max_filesize_str = ini_get("upload_max_filesize");
        $max_filesize_mb = (int)preg_replace("/\D+/", "", $max_filesize_str);
        $max_filesize_bytes = $max_filesize_mb * 1000;

        return $max_filesize_bytes;
    }

    /**
     * Helper function to change the name and type of an existing MySQL table. Note that exceptions aren't caught here:
     * the caller method has to wrap it.
     *
     * @param string $table The name of the table to alter.
     * @param string $old_col_name The old column name.
     * @param string $new_col_name The new column name.
     * @param string $col_type The new column data type.
     */
    public static function alterTableColumn($table, $old_col_name, $new_col_name, $col_type)
    {
        $db = Core::$db;

        $db->query("
            ALTER TABLE $table
            CHANGE $old_col_name $new_col_name $col_type
        ");
        $db->execute();

        extract(Hooks::processHookCalls("end", compact("table", "old_col_name", "new_col_name", "col_type"), array()), EXTR_OVERWRITE);
    }


    /**
     * Figures out an SQL LIMIT clause, based on page number & num per page.
     *
     * @param integer $page_num
     * @param integer $results_per_page a number or "all"
     * @return string
     */
    public static function getQueryPageLimitClause($page_num, $results_per_page)
    {
        $limit_clause = "";
        if ($results_per_page != "all") {
            if (empty($page_num) || !is_numeric($page_num)) {
                $page_num = 1;
            }
            $first_item = ($page_num - 1) * $results_per_page;
            $limit_clause = "LIMIT $first_item, $results_per_page";
        }

        return $limit_clause;
    }


    /**
     * Used for determining page load time.
     */
    public static function getMicrotimeFloat()
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

    /**
     * This function is used by the Smart Fill functionality. In order for the JS to be allowed to parse
     * the pages, they need to be on the same domain. This function figured out the method by which those
     * pages can be acquired for this particular server. It returns a string representing the method to
     * use, found in this order:
     *   1. "file_get_contents"
     *   2. "curl"
     *   3. "redirect" - this means that the form webpage is already on the same site, so it can be accessed
     *      directly
     *   4. "" - the empty string gets returned if none of the above methods apply. In this case, the user will
     *      have to manually upload copies of the files which are then created locally for parsing.
     *
     * TODO. There's a potentially bug with this function, which I haven't been able to solve for both PHP 4 & 5:
     * if the URL is invalid, file_get_contents can timeout with a fatal error. To reduce the likelihood of this
     * occurring, Step 2 of the Add Form process requires the user to have confirmed each of the form URLs.
     * Nevertheless, this needs to be addressed at some point.
     */
    public static function getJsWebpageParseMethod($form_url)
    {
        // set a 1 minute maximum execution time for this request
        @set_time_limit(60);
        $scrape_method = "";

        // we buffer the file_get_contents call in case the URL is invalid and a fatal error is generated
        // when the function time-outs
        if (@file_get_contents($form_url)) {
            $scrape_method = "file_get_contents";
        }
        if (function_exists("curl_init") && function_exists("curl_exec")) {
            $scrape_method = "curl";
        } else {
            $current_url = $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"];
            $current_url_info = parse_url($current_url);
            $form_url_info = parse_url($form_url);

            if (($current_url_info["host"] == $form_url_info["host"]) && ($current_url_info["port"] == $form_url_info["port"])) {
                $scrape_method = "redirect";
            }
        }

        return $scrape_method;
    }

    /**
     * This is called on all page loads. It checks to ensure that the person's sessions haven't timed out. If not,
     * it updates the last_activity_unixtime in the user's sessions - otherwise they're logged out.
     */
    public static function checkSessionsTimeout($auto_logout = true)
    {
        $now = date("U");
        $sessions_valid = true;

        //  print_r($_SESSION["ft"]["account"]);

        // check to see if the session has timed-out
        if (Sessions::exists("account.last_activity_unixtime") && Sessions::exists("account.sessions_timeout")) {
            $sessions_timeout_mins = Sessions::get("account.sessions_timeout");
            $timeout_secs = $sessions_timeout_mins * 60;

            if (Sessions::get("account.last_activity_unixtime") + $timeout_secs < $now) {
                if ($auto_logout) {
                    Core::$user->logout("notify_sessions_timeout");
                } else {
                    $sessions_valid = false;
                }
            }
        }

        // log this unixtime for checking the sessions timeout
        Sessions::set("account.last_activity_unixtime", $now);

        return $sessions_valid;
    }

    /**
     * Helper function to add a new data column the end of a table.
     *
     * @param string $table The name of the table to alter.
     * @param string $col_name The new column name.
     * @param string $col_type The new column data type.
     * @return array Array with indexes:<br/>
     *               [0]: true/false (success / failure)<br/>
     *               [1]: message string<br/>
     */
    public static function addTableColumn($table, $col_name, $col_type)
    {
        $db = Core::$db;

        $db->query("ALTER TABLE $table ADD $col_name $col_type");

        try {
            $db->execute();
            return array(true, "");
        } catch (PDOException $e) {
            return array(false, $e->getMessage());
        }
    }


    /**
     * Helper function to locate the value key in the request info. This is used in Fields::updateField(). It can be used
     * any time we use the jQuery serializeArray() function. The javascript version of this is called ft._extract_array_val
     *
     * @param array $array each index is a hash with two keys: name and value
     * @param string $name
     */
    public static function extractArrayVal($array, $name)
    {
        $value = "";
        for ($i=0; $i<count($array); $i++) {
            if ($array[$i]["name"] == $name) {
                $value = $array[$i]["value"];
                break;
            }
        }

        return $value;
    }

    /**
     * Return a date string from a MySQL datetime according based on an offset and a display format.
     * As of version 1.5.0, this function is language localized. The following php date() flags are
     * translated:
     * 			D    - Mon through Sun
     *      l    - Sunday through Saturday
     *      F    - January through December
     *      M    - Jan through Dec
     *      a    - am or pm
     *      A    - AM or PM
     *
     * Note that some flags (S for "st","rd", "nd" etc. and T for timezone, EST, MDT etc) are NOT
     * translated. This is. Also, this function only uses the standard Gregorian calendar. Nothing
     * fancy! My Unicode 5 book in on route, so I'll look into that in a later version. ;-)
     *
     * @param integer $offset the number of hours offset from GMT (- or +)
     * @param string $datetime the mysql datetime to format
     * @param string $format the date format to use (PHP's date() function).
     * @return string the date/time as a fully localized string
     */
    public static function getDate($offset, $datetime, $format)
    {
        $LANG = Core::$L;
        if (empty($offset)) {
            $offset = 0;
        }

        if (strlen($datetime) != 19) {
            return "";
        }

        $year = substr($datetime, 0, 4);
        $mon  = substr($datetime, 5, 2);
        $day  = substr($datetime, 8, 2);
        $hour = substr($datetime, 11, 2);
        $min  = substr($datetime, 14, 2);
        $sec  = substr($datetime, 17, 2);

        $timestamp = mktime($hour + $offset, $min, $sec, $mon, $day, $year);

        // if this is an English language (British, US English, English Canadian, etc), just
        // use the standard date() functionality (this is faster)
        if ($LANG["special_language"] == "English") {
            $date_str = date($format, $timestamp);
        } else {
            // here's how this works. We replace the special chars in the date formatting
            // string with a single "@" character - which has no special meaning for either date()
            // or in regular expressions - and keep track of the order in which they appear. Then,
            // we call date() to convert all other characters and then replace the @'s with their
            // translated versions.
            $special_chars = array("D", "l", "F", "M", "a", "A"); // M: short month, F: long month
            $char_map = array();
            $new_format = "";
            for ($char_ind=0; $char_ind<strlen($format); $char_ind++) {
                if (in_array($format[$char_ind], $special_chars)) {
                    $char_map[] = $format[$char_ind];
                    $format[$char_ind] = "@";
                }
                $new_format .= $format[$char_ind];
            }
            $date_str = date($new_format, $timestamp);

            // now replace the @'s with their translated equivalents
            $eng_strings = date(join(",", $char_map), $timestamp);
            $eng_string_arr = explode(",", $eng_strings);
            for ($char_ind=0; $char_ind<count($char_map); $char_ind++) {
                $eng_string = $eng_string_arr[$char_ind];

                switch ($char_map[$char_ind]) {
                    case "F":
                        $translated_str = $LANG["date_month_short_$eng_string"];
                        break;
                    case "M":
                        $translated_str = $LANG["date_month_$eng_string"];
                        break;
                    default:
                        $translated_str = $LANG["date_$eng_string"];
                        break;
                }
                $date_str = preg_replace("/@/", $translated_str, $date_str, 1);
            }
        }

        return $date_str;
    }


    /**
     * Undoes the "helpfulness" of Magic Quotes.
     *
     * @param mixed $input
     * @return mixed
     */
    public static function undoMagicQuotes($input)
    {
        if (!get_magic_quotes_gpc()) {
            return $input;
        }

        if (is_array($input)) {
            $output = array();
            foreach ($input as $k=>$i) {
                $output[$k] = General::undoMagicQuotes($i);
            }
        } else {
            $output = stripslashes($input);
        }

        return $output;
    }


    /**
     * Recursively strips tags from an array / string.
     *
     * @param mixed $input an array or string
     * @return mixes
     */
    public static function stripTags($input)
    {
        if (is_array($input)) {
            $output = array();
            foreach ($input as $k=>$i) {
                $output[$k] = General::stripTags($i);
            }
        } else {
            $output = strip_tags($input);
        }

        return $output;
    }


    /**
     * Checks a user-defined string is a valid MySQL datetime.
     *
     * @param string $datetime
     * @return boolean
     */
    public static function isValidDatetime($datetime)
    {
        if (preg_match("/^(\d{4})-(\d{2})-(\d{2}) ([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])$/", $datetime, $matches)) {
            if (checkdate($matches[2], $matches[3], $matches[1])) {
                return true;
            }
        }
        return false;
    }


    /**
     * Also called on the login page. This does a quick test to confirm the database tables exist as they should.
     * If not, it throws a serious error and prevents the user from logging in.
     */
    public static function verifyCoreTablesExist()
    {
        $db = Core::$db;
        $core_tables = Core::getCoreTables();
        $db_name = General::getCleanDbEntity(Core::getDbName());

        $db->query("SHOW TABLES FROM $db_name");
        $db->execute();

        $found_tables = array();
        foreach ($db->fetchAll() as $row) {
            $found_tables[] = $row[0];
        }

        $all_tables_found = true;
        $missing_tables = array();
        foreach ($core_tables as $table_name) {
            if (!in_array("{PREFIX}$table_name", $found_tables)) {
                $all_tables_found = false;
                $missing_tables[] = "{PREFIX}$table_name";
            }
        }

        if (!$all_tables_found) {
            $missing_tables_str = "<blockquote><pre>" . implode("\n", $missing_tables) . "</pre></blockquote>";
            Errors::majorError("Form Tools couldn't find all the database tables. Please check your /global/config.php file to confirm the <b>\$g_table_prefix</b> setting. The following tables are missing: {$missing_tables_str}");
            exit;
        }
    }


    /**
     * Added in 2.1.0, to get around a problem with database names having hyphens in them. I named the function
     * generically because it may come in handy for escaping other db aspects, like col names etc.
     *
     * @param string $str
     * @param string
     */
    public static function getCleanDbEntity($str)
    {
        if (strpos($str, "-") !== false) {
            $str = "`$str`";
        }
        return $str;
    }


    /**
     * Helper function to remove all empty strings from an array.
     *
     * @param array $array
     * @return array
     */
    public static function arrayRemoveEmptyEls($array)
    {
        $updated_array = array();
        foreach ($array as $el) {
            if (!empty($el)) {
                $updated_array[] = $el;
            }
        }
        return $updated_array;
    }


    /**
     * A multibyte version of str_split. Splits a string into chunks and returns the pieces in
     * an array.
     *
     * @param string $string The string to manipulate.
     * @param integer $split_length The number of characters in each chunk.
     * @return array an array of chunks, each of size $split_length. The last index contains the leftovers.
     *      If <b>$split_length</b> is less than 1, return false.
     */
    public static function mbStrSplit($string, $split_length = 1)
    {
        if ($split_length < 1) {
            return false;
        }

        $result = array();
        for ($i=0; $i<mb_strlen($string); $i+=$split_length) {
            $result[] = mb_substr($string, $i, $split_length);
        }

        return $result;
    }


    /**
     * Extracted from validate_fields. Simple function to test if a string is an email or not.
     *
     * @param string $str
     * @return boolean
     */
    public static function isValidEmail($str)
    {
        $regexp = "/^[a-z0-9]+([_\\.-][a-z0-9]+)*@([a-z0-9]+([\.-][a-z0-9]+)*)+\\.[a-z]{2,}$/i";
        return preg_match($regexp, $str);
    }


    /**
     * Returns a list of MySQL reserved words, to prevent the user accidentally entering a database field name
     * that has a special meaning for MySQL.
     */
    public static function getMysqlReservedWords()
    {
        $root_dir = Core::getRootDir();

        $words = @file("$root_dir/global/misc/mysql_reserved_words.txt");

        $clean_words = array();
        foreach ($words as $word) {
            $word = trim($word);
            if (!empty($word) && !in_array($word, $clean_words)) {
                $clean_words[] = $word;
            }
        }

        return $clean_words;
    }


    /**
     * A case insensitive version of in_array.
     */
    public static function inArrayCaseInsensitive($value, $array)
    {
        foreach ($array as $item) {
            if (is_array($item)) {
                $return = General::inArrayCaseInsensitive($value, $item);
            } else {
                $return = strtolower($item) == strtolower($value);
            }

            if ($return) {
                return $return;
            }
        }

        return false;
    }


    /**
     * A simple helper function to convert any string to a "slug" - an alphanumeric, "_" and/or "-" string
     * for use in (e.g.) generating filenames.
     *
     * @param string $string
     * @return string
     */
    public static function createSlug($string)
    {
        $str = trim($string);
        $str = preg_replace('/[^a-zA-Z0-9]/', '_', $str);
        $str = preg_replace('/_{2,}/', "_", $str);

        return $str;
    }


    /**
     * Generates a random password of a certain length.
     *
     * @param integer $length the number of characters in the password
     * @return string the password
     */
    public static function generatePassword($length = 8)
    {
        $password = "";
        $possible = "0123456789abcdfghjkmnpqrstvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_-";
        $i = 0;

        // add random characters to $password until $length is reached
        while ($i < $length) {
            // pick a random character from the possible ones
            $char = substr($possible, mt_rand(0, strlen($possible)-1), 1);

            // we don't want this character if it's already in the password
            if (!strstr($password, $char)) {
                $password .= $char;
                $i++;
            }
        }

        return $password;
    }


    /**
     * This was added in 2.1.0. and replaces ft_build_and_cache_upgrade_info() which really wasn't necessary.
     * It returns a hash of information to pass in a hidden form when the user clicks "Update".
     */
    public static function getFormtoolsInstalledComponents()
    {
        $core_version = Core::getCoreVersion();
        $release_date = Core::getReleaseDate();
        $release_type = Core::getReleaseType();

        $settings = Settings::get();

        // a hash storing the installed component info
        $components = array();

        $version = $core_version;
        if ($release_type == "alpha") {
            $version = "{$core_version}-alpha-{$release_date}";
        } else if ($release_type == "beta") {
            $version = "{$core_version}-beta-{$release_date}";
        }

        $components["m"]   = $version;
        $components["rt"]  = $release_type;
        $components["rd"]  = $release_date;
        $components["api"] = $settings["api_version"];

        // not sure about this, but I've added it for backward compatibility, just in case...
        if ($release_type == "beta") {
            $components["beta"] = "yes";
            $components["bv"] = $version;
        }

        // get the theme info
        $themes = Themes::getList();
        $count = 1;
        foreach ($themes as $theme_info) {
            $components["t{$count}"]  = $theme_info["theme_folder"];
            $components["tv{$count}"] = $theme_info["theme_version"];
            $count++;
        }

        // get the module info
        $modules = Modules::getList();
        $count = 1;
        foreach ($modules as $module_info) {
            $components["m{$count}"]  = $module_info["module_folder"];
            $components["mv{$count}"] = $module_info["version"];
            $count++;
        }

        return $components;
    }


    /**
     * Generates the placeholders for a particular form submission. This is used in the email templates, and here and there
     * for providing placeholder functionality to fields (like the "Edit Submission Label" textfield for a form, where they can
     * enter placeholders populated here).
     *
     * This returns ALL available placeholders for a form, regardless of View.
     *
     * @param integer $form_id
     * @param integer $submission_id
     * @param array $client_info a hash of information about the appropriate user (optional)
     * @return array a hash of placeholders and their replacement values (e.g. $arr["FORMURL"] => 17)
     */
    public static function getSubmissionPlaceholders($form_id, $submission_id, $client_info = "")
    {
        $root_url = Core::getRootUrl();

        $placeholders = array();

        $settings        = Settings::get();
        $form_info       = Forms::getForm($form_id);
        $submission_info = Submissions::getSubmission($form_id, $submission_id);
        $admin_info      = Administrator::getAdminInfo();
        $file_field_type_ids = FieldTypes::getFileFieldTypeIds();
        $field_types     = FieldTypes::get(true);

        // now loop through the info stored for this particular submission and for this particular field,
        // add the custom submission responses to the placeholder hash

        $form_field_params = array(
            "include_field_type_info"   => true,
            "include_field_settings"    => true,
            "evaluate_dynamic_settings" => true
        );
        $form_fields = Fields::getFormFields($form_id, $form_field_params);

        foreach ($submission_info as $field_info) {
            $field_id      = $field_info["field_id"];
            $field_name    = $field_info["field_name"];
            $field_type_id = $field_info["field_type_id"];

            if ($field_info["is_system_field"] == "no") {
                $placeholders["QUESTION_$field_name"] = $field_info["field_title"];
            }

            if (in_array($field_type_id, $file_field_type_ids)) {
                $field_settings = Fields::getFieldSettings($field_id);
                $placeholders["FILENAME_$field_name"] = $field_info["content"];
                $placeholders["FILEURL_$field_name"]  = "{$field_settings["folder_url"]}/{$field_info["content"]}";
            } else {
                $detailed_field_info = array();
                foreach ($form_fields as $curr_field_info) {
                    if ($curr_field_info["field_id"] != $field_id) {
                        continue;
                    }
                    $detailed_field_info = $curr_field_info;
                    break;
                }

                $params = array(
                    "form_id"       => $form_id,
                    "submission_id" => $submission_id,
                    "value"         => $field_info["content"],
                    "field_info"    => $detailed_field_info,
                    "field_types"   => $field_types,
                    "settings"      => $settings,
                    "context"       => "email_template"
                );
                $value = FieldTypes::generateViewableField($params);
                $placeholders["ANSWER_$field_name"] = $value;

                // for backward compatibility
                if ($field_name == "core__submission_date") {
                    $placeholders["SUBMISSIONDATE"] = $value;
                } else if ($field_name == "core__last_modified") {
                    $placeholders["LASTMODIFIEDDATE"] = $value;
                } else if ($field_name == "core__ip_address") {
                    $placeholders["IPADDRESS"] = $value;
                }
            }
        }

        // other misc placeholders
        $placeholders["ADMINEMAIL"]   = $admin_info["email"];
        $placeholders["FORMNAME"]     = $form_info["form_name"];
        $placeholders["FORMURL"]      = $form_info["form_url"];
        $placeholders["SUBMISSIONID"] = $submission_id;
        $placeholders["LOGINURL"]     = $root_url . "/index.php";

        if (!empty($client_info)) {
            $placeholders["EMAIL"]       = $client_info["email"];
            $placeholders["FIRSTNAME"]   = $client_info["first_name"];
            $placeholders["LASTNAME"]    = $client_info["last_name"];
            $placeholders["COMPANYNAME"] = $client_info["company_name"];
        }

        extract(Hooks::processHookCalls("end", compact("placeholders"), array("placeholders")), EXTR_OVERWRITE);

        return $placeholders;
    }

    // bizarrely, you can pass expressions to empty() until PHP 5.5. (?!?!?) so this is a convenience wrapper
    public static function isEmpty($var)
    {
        return empty($var);
    }
}

