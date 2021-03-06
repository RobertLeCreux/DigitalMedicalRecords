<?php


namespace FormTools\Modules\Pages;

use FormTools\Core;
use FormTools\General;
use FormTools\Menus;
use FormTools\Module as FormToolsModule;
use FormTools\Modules;
use FormTools\Pages as CorePages; // just for clarity
use PDO, PDOException;


class Module extends FormToolsModule
{
    protected $moduleName = "Pages";
    protected $moduleDesc = "This module lets you define your own custom pages to link to from within the Form Tools UI. This lets you to add help pages, client splash pages or any other custom information.";
    protected $author = "Ben Keen";
    protected $authorEmail = "ben.keen@gmail.com";
    protected $authorLink = "http://formtools.org";
    protected $version = "2.0.0";
    protected $date = "2017-09-25";
    protected $originLanguage = "en_us";
    protected $jsFiles = array(
        "{FTROOT}/global/codemirror/js/codemirror.js",
        "{MODULEROOT}/scripts/pages.js"
    );

    protected $nav = array(
        "word_pages"      => array("index.php", false),
        "phrase_add_page" => array("add.php", true),
        "word_settings"   => array("settings.php", false),
        "word_help"       => array("help.php", false)
    );

    public function __construct() {
        parent::__construct(Core::$user->getLang());
        CorePages::registerPage("custom_page", "/modules/pages/page.php");
    }

    /**
     * The installation script for the Pages module. This creates the module_pages database table.
     */
    public function install($module_id)
    {
        $db = Core::$db;

        // our create table query
        $queries = array();
        $queries[] = "
            CREATE TABLE {PREFIX}module_pages (
                page_id mediumint(8) unsigned NOT NULL auto_increment,
                page_name varchar(50) NOT NULL,
                access_type enum('admin','public','private') NOT NULL default 'admin',
                content_type enum('html','php','smarty') NOT NULL default 'html',
                use_wysiwyg enum('yes','no') NOT NULL default 'yes',
                heading varchar(255) default NULL,
                content text,
                PRIMARY KEY (page_id)
            ) DEFAULT CHARSET=utf8
        ";

        $queries[] = "
            CREATE TABLE IF NOT EXISTS {PREFIX}module_pages_clients (
                page_id mediumint(9) unsigned NOT NULL,
                client_id mediumint(9) unsigned NOT NULL,
                PRIMARY KEY (page_id, client_id)
            ) DEFAULT CHARSET=utf8
        ";

        $queries[] = "INSERT INTO {PREFIX}settings (setting_name, setting_value, module) VALUES ('num_pages_per_page', '10', 'pages')";

        $success = true;
        $message = "";
        try {
            $db->beginTransaction();
            foreach ($queries as $query) {
                $db->query($query);
                $db->execute();
            }
            $db->processTransaction();

        } catch (PDOException $e) {
            $db->rollbackTransaction();
            $L = $this->getLangStrings();
            $success = false;
            $message = General::evalSmartyString($L["notify_problem_installing"], array("error" => $e->getMessage()));
        }

        return array($success, $message);
    }


    /**
     * The uninstallation script for the Pages module. This basically does a little clean up
     * on the database to ensure it doesn't leave any footprints. Namely:
     *   - the module_pages table is removed
     *   - any references in client or admin menus to any Pages are removed
     *   - if the default login page for any user account was a Page, it attempts to reset it to
     *     a likely login page (the Forms page for both).
     *
     * The message returned by the script informs the user the module has been uninstalled, and warns them
     * that any references to any of the Pages in the user accounts has been removed.
     *
     * @return array [0] T/F, [1] success message
     */
    public function uninstall($module_id)
    {
        $db = Core::$db;

        $success = true;

        try {
            $db->beginTransaction();

            $db->query("SELECT page_id FROM {PREFIX}module_pages");
            $db->execute();
            $rows = $db->fetchAll();

            foreach ($rows as $row) {
                $page_id = $row["page_id"];
                $db->query("DELETE FROM {PREFIX}menu_items WHERE page_identifier = :page_identifier");
                $db->bind("page_identifier", "page_{$page_id}");
                $db->execute();
            }

            // delete the Pages module tables
            $db->query("DROP TABLE {PREFIX}module_pages");
            $db->execute();

            $db->query("DROP TABLE {PREFIX}module_pages_clients");
            $db->execute();

            // update sessions in case a Page was in the administrator's account menu
            Menus::cacheAccountMenu(Core::$user->getAccountId());

            $db->query("DELETE FROM {PREFIX}settings WHERE module = 'pages'");

            $L = $this->getLangStrings();
            $message = $L["notify_module_uninstalled"];

        } catch (PDOException $e) {
            $db->rollbackTransaction();
            $success = false;
            $message = $e->getMessage();
        }

        return array($success, $message);
    }


    /**
     * Updates the setting on the Settings page.
     *
     * @param array $info
     * @return array [0] true/false
     *               [1] message
     */
    public function updateSettings($info)
    {
        $L = $this->getLangStrings();

        Modules::setModuleSettings(array(
            "num_pages_per_page" => $info["num_pages_per_page"]
        ));

        return array(true, $L["notify_settings_updated"]);
    }


    /**
     * Adds a new page to the module_pages table.
     *
     * @param array $info
     * @return array standard return array
     */
    public function addPage($info)
    {
        $LANG = Core::$L;
        $db = Core::$db;

        $content_type = $info["content_type"];
        $access_type = $info["access_type"];
        $use_wysiwyg = $info["use_wysiwyg_hidden"];

        $content = $info["codemirror_content"];
        if ($content_type == "html" && $use_wysiwyg == "yes") {
            $content = $info["wysiwyg_content"];
        }

        $success = true;
        $message = $LANG["notify_page_added"];
        $page_id = "";

        try {
            $db->query("
                INSERT INTO {PREFIX}module_pages (page_name, content_type, access_type, use_wysiwyg, heading, content)
                VALUES (:page_name, :content_type, :access_type, :use_wysiwyg, :heading, :content)
            ");
            $db->bindAll(array(
                "page_name" => $info["page_name"],
                "content_type" => $content_type,
                "access_type" => $access_type,
                "use_wysiwyg" => $use_wysiwyg,
                "heading" => $info["heading"],
                "content" => $content
            ));
            $db->execute();

            $page_id = $db->getInsertId();

            if ($access_type == "private" && isset($info["selected_client_ids"])) {
                foreach ($info["selected_client_ids"] as $client_id) {
                    $db->query("
                        INSERT INTO {PREFIX}module_pages_clients (page_id, client_id)
                        VALUES (:page_id, :client_id)
                    ");
                    $db->bindAll(array(
                        "page_id" => $page_id,
                        "client_id" => $client_id
                    ));
                    $db->execute();
                }
            }
        } catch (PDOException $e) {
            print_r($e->getMessage());
            $success = false;
            $message = $LANG["notify_page_not_added"];
        }

        return array($success, $message, $page_id);
    }


    /**
     * Deletes a page.
     *
     * TODO: delete this page from any menus.
     *
     * @param integer $page_id
     */
    public function deletePage($page_id)
    {
        $db = Core::$db;
        $L = $this->getLangStrings();

        if (empty($page_id) || !is_numeric($page_id)) {
            return array(false, "");
        }

        $db->query("DELETE FROM {PREFIX}module_pages WHERE page_id = :page_id");
        $db->bind("page_id", $page_id);
        $db->execute();

        $db->query("
            DELETE FROM {PREFIX}menu_items
            WHERE page_identifier = :page_identifier
        ");
        $db->bind("page_identifier", "page_{$page_id}");
        $db->execute();

        // this is dumb, but better than nothing. If we just updated any menus, re-cache the admin menu just in case
        if ($db->numRows() > 0) {
            Menus::cacheAccountMenu(1);
        }

        return array(true, $L["notify_delete_page"]);
    }


    /**
     * Returns all information about a particular Page.
     *
     * @param integer $page_id
     * @return array
     */
    public function getPage($page_id)
    {
        $db = Core::$db;

        $db->query("SELECT * FROM {PREFIX}module_pages WHERE page_id = :page_id");
        $db->bind("page_id", $page_id);
        $db->execute();

        $page_info = $db->fetch();

        $db->query("SELECT client_id FROM {PREFIX}module_pages_clients WHERE page_id = :page_id");
        $db->bind("page_id", $page_id);
        $db->execute();

        $page_info["clients"] = $db->fetchAll(PDO::FETCH_COLUMN);

        return $page_info;
    }


    /**
     * Returns a page worth of Pages from the Pages module.
     *
     * @param mixed $num_per_page a number or "all"
     * @param integer $page_num
     * @return array
     */
    public function getPages($num_per_page, $page_num = 1)
    {
        $db = Core::$db;

        if ($num_per_page == "all") {
            $db->query("SELECT * FROM {PREFIX}module_pages ORDER BY heading");
        } else {

            // determine the offset
            if (empty($page_num)) {
                $page_num = 1;
            }
            $first_item = ($page_num - 1) * $num_per_page;

            $db->query("SELECT * FROM {PREFIX}module_pages ORDER BY heading LIMIT $first_item, $num_per_page");
        }
        $db->execute();
        $results = $db->fetchAll();

        $db->query("SELECT count(*) FROM {PREFIX}module_pages");
        $db->execute();

        return array(
            "results" => $results,
            "num_results" => $db->fetch(PDO::FETCH_COLUMN)
        );
    }


    public function updatePage($page_id, $info)
    {
        $db = Core::$db;
        $LANG = Core::$L;

        $content_type = $info["content_type"];
        $use_wysiwyg = $info["use_wysiwyg_hidden"];
        $access_type = $info["access_type"];

        $content = $info["codemirror_content"];
        if ($content_type == "html" && $use_wysiwyg == "yes") {
            $content = $info["wysiwyg_content"];
        }

        $db->query("
            UPDATE {PREFIX}module_pages
            SET    page_name = :page_name,
                   content_type = :content_type,
                   access_type = :access_type,
                   use_wysiwyg = :use_wysiwyg,
                   heading = :heading,
                   content = :content
            WHERE  page_id = :page_id
        ");
        $db->bindAll(array(
            "page_name" => $info["page_name"],
            "content_type" => $content_type,
            "access_type" => $access_type,
            "use_wysiwyg" => $use_wysiwyg,
            "heading" => $info["heading"],
            "content" => $content,
            "page_id" => $page_id
        ));
        $db->execute();

        $db->query("DELETE FROM {PREFIX}module_pages_clients WHERE page_id = :page_id");
        $db->bind("page_id", $page_id);
        $db->execute();

        if ($access_type == "private" && isset($info["selected_client_ids"])) {
            foreach ($info["selected_client_ids"] as $client_id) {
                $db->query("INSERT INTO {PREFIX}module_pages_clients (page_id, client_id) VALUES (:page_id, :client_id)");
                $db->bindAll(array(
                    "page_id" => $page_id,
                    "client_id" => $client_id
                ));
                $db->execute();
            }
        }

        return array(true, $LANG["notify_page_updated"]);
    }
}
