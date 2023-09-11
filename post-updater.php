<?php

/**
 * Plugin Name:商品内容定期更新
 * Plugin URI: プラグインのURL
 * Description:商品情報を基幹システムと連携し、更新する
 * Version: 2.0.0
 * Author: Yu Ishiga
 * Author URI: https://backcountry-works.com
 */
/**
 * set function
 */

if (!defined('ABSPATH')) exit;
define('MY_PLUGIN_PATH2', plugin_dir_path(__FILE__));
require_once MY_PLUGIN_PATH2 . "DBconnection_X.php";

use post_updater\DBconnection_X;
use post_updater\CSV_controller;



/**
 * 同期設定取得
 */
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////
define('SYNC_PRICE', get_option('post-updater_price') == 1 ? true : false); //価格
define('SYNC_NAME', get_option('post-updater_name') == 1 ? true : false); //商品名
define('SYNC_DESCRIPTION', get_option('post-updater_description') == 1 ? true : false); //説明
define('SYNC_RESERVE', get_option('post-updater_reserve')); //予約時間
define('WP_DB_USER', get_option('wp_db_user'));
define('WP_DB_TABLE', get_option('wp_db_table'));
define('WP_DB_PSWD', get_option('wp_db_pswd'));
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////

/**
 * CRON設定
 */
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////
add_action('MD_BlogCronHook', 'MD_BlogDo');

function MD_BlogCronStart()
{
    //do twice per day
    wp_schedule_event(time(), 'every_15min', 'MD_BlogCronHook');
}
register_activation_hook(__FILE__, 'MD_BlogCronStart');


function MD_BlogCronStop()
{
    wp_clear_scheduled_hook('MD_BlogCronHook');
}
register_deactivation_hook(__FILE__, 'MD_BlogCronStop');

add_filter('cron_schedules', 'my_add_intervals'); // 「cron_schedules」フックを使ってスケジュール追加
function my_add_intervals($schedules)
{
    // 1週間に1回のスケジュールを追加する
    $schedules['weekly'] = array(
        'interval' => 604800,
        'display' => __('Once Weekly')
    );
    // 30分に1回のスケジュールを追加する
    $schedules['every_15min'] = array( // 「every_30min」という名前でスケジュール登録
        'interval' => 900, // 実行間隔 この場合は30分なので、60(秒) * 30(分) = 1800(秒)
        'display' => __('Every 15 minutes') // 30分おきに実行
    );
    return $schedules;
}
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////

/**
 * 管理画面
 */
add_action('init', 'Postupdater_admin::init');
class Postupdater_admin
{
    const VERSION           = '1.0.0';
    const PLUGIN_ID         = 'post-updater';
    const CREDENTIAL_ACTION = self::PLUGIN_ID . '-nonce-action';
    const CREDENTIAL_NAME   = self::PLUGIN_ID . '-nonce-key';
    const PLUGIN_DB_PREFIX  = self::PLUGIN_ID . '_';
    const CONFIG_MENU_SLUG  = self::PLUGIN_ID . '-config';
    const COMPLETE_CONFIG  = 'update-date-complete';


    static function init()
    {
        return new self();
    }

    function __construct()
    {
        if (is_admin() && is_user_logged_in()) {
            // メニュー追加
            add_action('admin_menu', [$this, 'set_plugin_menu']);
            // コールバック関数定義
            add_action('admin_init', [$this, 'save_config']);
        }
    }

    function set_plugin_menu()
    {
        add_menu_page(
            '商品情報同期システム',
            '商品情報同期',
            'manage_options',
            'config_post_updater',
            [$this, 'show_config_updater'],
            'dashicons-format-gallery',
            99
        );
    }

    function show_config_updater()
    {

        $name = get_option(self::PLUGIN_DB_PREFIX . "name");
        $price = get_option(self::PLUGIN_DB_PREFIX . "price");
        $description = get_option(self::PLUGIN_DB_PREFIX . "description");
        $reserve = get_option(self::PLUGIN_DB_PREFIX . "reserve");
?>
        <style>
            .pu-setting label {
                display: flex;
                margin: 10px 0;
            }
        </style>
        <div class="wrap">
            <h1>商品情報同期設定</h1>
            <p>
                基幹システムの情報を項目ごとに同期するかどうかを設定できます。
            </p>

            <form action="" method='post' id="my-submenu-form">
                <?php // ②：nonceの設定 
                ?>
                <?php wp_nonce_field(self::CREDENTIAL_ACTION, self::CREDENTIAL_NAME) ?>

                <p class="pu-setting">
                    <label for="name"><strong>商品名：</strong>
                        <select name="name">
                            <option value="1" <?php if ($name == 1) echo "selected"; ?>>ON</option>
                            <option value="0" <?php if ($name == "") echo "selected"; ?>>OFF</option>
                        </select>
                    </label>
                    <label for="price"><strong>価格：</strong>
                        <select name="price">
                            <option value="1" <?php if ($price == 1) echo "selected"; ?>>ON</option>
                            <option value="0" <?php if ($price == "") echo "selected"; ?>>OFF</option>
                        </select>
                    </label>
                    <label for="description"><strong>説明：</strong>
                        <select name="description">
                            <option value="1" <?php if ($description == 1) echo "selected"; ?>>ON</option>
                            <option value="0" <?php if ($description == "") echo "selected"; ?>>OFF</option>
                        </select>
                    </label>
                    <label for="reserve">
                        <storong>予約：</storong><br>
                        予約をすると、上の項目でOFFになっているものが予約日時にONなります。<br>
                        予約を利用しない場合は空白にしてください。<br>
                        <input name="reserve" type="datetime-local" value="<?= $reserve ?>">
                    </label>
                </p>

                <p><input type='submit' value='保存' class='button button-primary button-large'></p>
            </form>
            <h2>強制同期</h2>
            <p>通常15分ごとに更新していますが、今すぐ更新したいときはこのボタンを使用してください。<br>
                何回も押すとサーバーの負担になります。５分以上おいてから押してください。</p>
            <p>
                <form action="" method="post" id="force-update">
                    <?php wp_nonce_field(self::CREDENTIAL_ACTION, self::CREDENTIAL_NAME) ?>
                    <input type="hidden" name="f-update" value="1" />
                    <input type="submit" value="今すぐ同期する" class='button button-primary button-large' />
                </form>
            </p>
        </div>
<?php
    }

    function save_config()
    {

        // nonceで設定したcredentialのチェック 
        if (isset($_POST[self::CREDENTIAL_NAME]) && $_POST[self::CREDENTIAL_NAME]) {
            if (check_admin_referer(self::CREDENTIAL_ACTION, self::CREDENTIAL_NAME) && !isset($_POST["f-update"])) {

                // 保存処理
                $key   = ["name", "price", "description", "reserve"];
                foreach ($key as $value) {
                    $result = $_POST[$value] ? $_POST[$value] : "";
                    update_option(self::PLUGIN_DB_PREFIX . $value, $result);
                }

                $completed_text = "設定の保存が完了しました。";

                // 保存が完了したら、wordpressの機構を使って、一度だけメッセージを表示する
                set_transient(self::COMPLETE_CONFIG, $completed_text, 5);

                // 設定画面にリダイレクト
                wp_safe_redirect(menu_page_url(self::CONFIG_MENU_SLUG), false);
            }elseif(check_admin_referer(self::CREDENTIAL_ACTION, self::CREDENTIAL_NAME) && $_POST["f-update"] == 1){
                MD_BlogDo();
            }
        }
    }
}

/**
 * メイン
 * $value[0] = '商品コード'
 * $value[1] = '有効在庫'
 * $value[2] = '入荷予定日'
 * $value[3] = '入荷予定数'
 * $value[4] = '注残数'
 * $value[5] = '公開ステータス(ID)'
 * $value[6] = '商品名'
 * $value[7] = '商品説明(詳細)'
 * $value[8] = 'フリーエリア'
 * $value[9] = '掛率ID'
 * $value[10] = 'サイズ１'
 * $value[11] = 'サイズ２'
 * $value[12] = '重量１'
 * $value[13] = '重量２'
 * $value[14] = '付属品'
 * $value[15] = '素材'
 * $value[16] = '生産国'
 * $value[17] = 'JANコード'
 * $value[18] = '定員'
 * $value[19] = '入口数'
 * $value[20] = '自立'
 * $value[21] = '室内高'
 * $value[22] = '厚さ'
 * $value[23] = 'R値'
 * $value[24] = '耐荷重'
 * $value[25] = '地上高'
 * $value[26] = 'バッテリー'
 * $value[27] = '温度帯'
 * $value[28] = 'ゴトク'
 * $value[29] = '燃料'
 * $value[30] = '燃焼時間'
 * $value[31] = '熱量'
 * $value[32] = '沸騰時間'
 * $value[33] = 'ストーブ高'
 * $value[34] = '防水レベル'
 * $value[35] = 'PVCフリー'
 * $value[36] = 'youtube動画ID(|で区切る)'
 * $value[37] = 'EC販売'
 * $value[38] = '状態'
 * $value[39] = '上代価格(税別)'
 * $value[40] = '分類１コード'
 * $value[41] = '分類１名称'
 * $value[42] = '分類２コード'
 * $value[43] = '分類２名称'
 * $value[44] = '分類３コード'
 * $value[45] = '分類３名称'
 * $value[46] = '分類４コード'
 * $value[47] = '分類４名称'
 */
function MD_BlogDo()
{
    global $wpdb;

    $id2sku = [];
    /**
     * 関数初期化
     */
    $db = new DBconnection_X("localhost", WP_DB_TABLE, WP_DB_USER, WP_DB_PSWD);
    $csv = new CSV_controller("/home/xs683807/mot-ec.com/public_html/order/auto_upload/csv/zaiko.csv");

    if (SYNC_RESERVE != "") {
        $date = new DateTime(str_replace("T", " ", SYNC_RESERVE), new DateTimeZone("Asia/Tokyo"));
        $reserve = $date->format("U");

        if (time() >= $reserve) {
            update_option("post-updater_reserve", "");
            update_option("post-updater_name", "1");
            update_option("post-updater_price", "1");
            update_option("post-updater_description", "1");
        }
    }

    /**
     * データベース取得
     */
    $wp_posts = $db->getDatabase("wp_posts");
    $wp_postmeta = $db->getDatabase("wp_postmeta");
    $wp_term_relationships = $db->getDatabase("wp_term_relationships");
    $wp_wc_order_stats = $db->getDatabase("wp_wc_order_stats");
    $wp_wp_wc_order_product_lookup = $db->getDatabase("wp_wc_order_product_lookup");
    $csv_data = $csv->readCSV();

    /**
     * 設定バリエーション
     */
    $wp_postmeta2 = $db->searchDatabase("wp_postmeta", "meta_key", "_variation_description");

    /**
     * オーダーから処理中のorder_idを抽出
     */
    foreach ($wp_wc_order_stats as $value) {
        if ($value["status"] == "wc-completed") {
            $orders[] = $value["order_id"];
        }
    }


    /**
     * オーダーの商品詳細取得
     */
    foreach ($wp_wp_wc_order_product_lookup as $value) {
        foreach ($orders as $vv) {
            if ($value["order_id"] == $vv) {
                $product_id_order[] = [$value["product_id"], $value["product_qty"]];
            }
        }
    }



    foreach ($wp_postmeta as &$value) {
        $id2sku[$value["post_id"]][$value["meta_key"]] = $value["meta_value"];
        /**
         * skuからpost_id用
         */
        if ($value["meta_key"] == "_sku") {
            $skutoid[$value["meta_value"]] = $value["post_id"];
        }
    }

    foreach ($id2sku as $key => $value) {
        if (array_key_exists("_sku", $value)) {
            $data[$key] = $value;
        }
    }

    foreach ($wp_posts as $value) {
        foreach ($data as $key => $value2) {
            if ($value["ID"] == $key) {
                $data_final[$data[$key]["_sku"]] = $data[$key] + $value;
                /**
                 * バリエーション用の変数 $simpleorvariable
                 */
                $simpleorvariable[$value["ID"]] = [$value["post_type"], $value["post_parent"], $value["guid"], $data[$key]["_sku"], $value["post_excerpt"], $value["ID"]];
            }
        }
    }



    foreach ($wp_term_relationships as $value) {
        $cate[$value["object_id"]][] = $value["term_taxonomy_id"];
    }

    $debug = "";
    //$pro_count = false;
    $ex_count = 0;
    foreach ($csv_data as $value) {
        if (empty($data_final[$value[0]])) continue; //品番がないものはスキップ
        $ex_count++;

        /**
         * 変数設定
         */
        $name_t = explode("/", $value[6]);
        $name = array_filter($name_t);
        $name = implode(" ", $name);
        $name = mb_convert_kana($name, "KHVA");


        /**
         * Description加工開始
         */
        $postcontent = "";
        $postcontent .= "<p>" . $value[8] . "</p>";
        $postcontent .= <<<EOT
        <table>
        EOT;

        /**
         * カテゴリごとの分岐設定
         */
        #cateflag テント:0,ストーブ:1,クッカー:2,浄水器:3
        $cate_flag = 99; //初期設定
        if ($simpleorvariable[$data_final[$value[0]]["ID"]][0] == "product_variation") {
            for ($i = 0; $i < count($cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]]); $i++) {
                if ($cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 320 || $cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 332 || $cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 389 || $cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 385 || $cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 333) {
                    $cate_flag = 0;
                    continue;
                } elseif ($cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 334 || $cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 387 || $cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 368 || $cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 377) {
                    $cate_flag = 1;
                    continue;
                } elseif ($cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 382 || $cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 325 || $cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 326 || $cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 390 || $cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 370 || $cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 338) {
                    $cate_flag = 2; //4|9|225|323|324|326|446|936|937
                    continue;
                } elseif ($cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 620 ||  $cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 470) {
                    $cate_flag = 3;
                    continue;
                } elseif ($cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 204) {
                    $name = "（Ｐ）" . $name;
                    continue;
                } elseif ($cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 328) {
                    $cate_flag = 4;
                } elseif ($cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 378 || $cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 381 || $cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 379) {
                    $cate_flag = 5;
                } elseif ($cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 322) {
                    $cate_flag = 6;
                } elseif ($cate[$simpleorvariable[$data_final[$value[0]]["ID"]][1]][$i] == 336) {
                    $cate_flag = 7;
                }
            }
        } else {
            for ($i = 0; $i < count($cate[$data_final[$value[0]]["ID"]]); $i++) {
                if ($cate[$data_final[$value[0]]["ID"]][$i] == 320 || $cate[$data_final[$value[0]]["ID"]][$i] == 332 || $cate[$data_final[$value[0]]["ID"]][$i] == 389 || $cate[$data_final[$value[0]]["ID"]][$i] == 385 || $cate[$data_final[$value[0]]["ID"]][$i] == 333) {
                    $cate_flag = 0;
                    continue;
                } elseif ($cate[$data_final[$value[0]]["ID"]][$i] == 334 || $cate[$data_final[$value[0]]["ID"]][$i] == 387 || $cate[$data_final[$value[0]]["ID"]][$i] == 368 || $cate[$data_final[$value[0]]["ID"]][$i] == 377) {
                    $cate_flag = 1;
                    continue;
                } elseif ($cate[$data_final[$value[0]]["ID"]][$i] == 382 || $cate[$data_final[$value[0]]["ID"]][$i] == 325 || $cate[$data_final[$value[0]]["ID"]][$i] == 326 || $cate[$data_final[$value[0]]["ID"]][$i] == 390 || $cate[$data_final[$value[0]]["ID"]][$i] == 370 || $cate[$data_final[$value[0]]["ID"]][$i] == 338) {
                    $cate_flag = 2;
                    continue;
                } elseif ($cate[$data_final[$value[0]]["ID"]][$i] == 620 ||  $cate[$data_final[$value[0]]["ID"]][$i] == 470) {
                    $cate_flag = 3;
                    continue;
                } elseif ($cate[$data_final[$value[0]]["ID"]][$i] == 204) {
                    $name = "（Ｐ）" . $name;
                    continue;
                } elseif ($cate[$data_final[$value[0]]["ID"]][$i] == 328) {
                    $cate_flag = 4;
                } elseif ($cate[$data_final[$value[0]]["ID"]][$i] == 378 || $cate[$data_final[$value[0]]["ID"]][$i] == 381 || $cate[$data_final[$value[0]]["ID"]][$i] == 379) {
                    $cate_flag = 5;
                } elseif ($cate[$data_final[$value[0]]["ID"]][$i] == 322) {
                    $cate_flag = 6;
                } elseif ($cate[$data_final[$value[0]]["ID"]][$i] == 336) {
                    $cate_flag = 7;
                }
            }
        }

        /**
         * スペック表作成
         */
        //テント
        $spec0 = [10 => "サイズ", 11 => "収納サイズ", 12 => "総重量", 13 => "最小重量", 14 => "付属品", 15 => "素材", 16 => "生産国", 17 => "jan", 18 => "定員", 19 => "入口数", 20 => "自立", 21 => "室内高", 22 => "対応フットプリント"];
        //ストーブ
        $spec1 = [10 => "サイズ", 11 => "収納サイズ", 12 => "総重量", 13 => "最小重量", 14 => "付属品", 15 => "素材", 16 => "生産国", 17 => "jan", 18 => "容量", 28 => "ゴトクサイズ", 29 => "使用可能燃料", 30 => "燃焼時間", 31 => "熱量", 32 => "沸騰時間", 33 => "ストーブ高"];
        //マットレス・コット・寝袋 
        $spec2 = [10 => "サイズ", 11 => "収納サイズ", 12 => "総重量", 13 => "最小重量", 14 => "付属品", 15 => "素材", 16 => "生産国", 17 => "jan", 22 => '厚さ', 23 => 'R値', 24 => '耐荷重', 25 => '地上高', 26 => 'バッテリー', 27 => '使用温度帯'];
        //浄水器
        $spec3 = [10 => "サイズ", 11 => "収納サイズ", 12 => "総重量", 13 => "最小重量", 14 => "付属品", 15 => "素材", 16 => "生産国", 17 => "jan", 21 => '原生動物', 22 => 'バクテリア', 23 => 'ウイルス', 24 => '微粒子', 25 => '化学薬品/毒素(味/臭い)', 26 => '流量(1L/分)', 27 => '流量(ストローク/L)', 28 => 'フィルターカートリッジの寿命', 29 => '現場でのクリーニング', 30 => '現場でのメンテナンス', 31 => '浄水方式', 32 => 'リザーバーの取付け', 33 => 'フィルターカートリッジ交換インジケーター'];
        //LED
        $spec4 = [10 => "サイズ", 11 => "収納サイズ", 12 => "総重量", 13 => "ダウン量", 14 => "付属品", 15 => "素材", 16 => "生産国", 26 => "バッテリー", 28 => "明るさ", 30 => "点灯時間", 31 => "仕様", 33 => "充電時間（目安）", 34 => "防水レベル"];
        //シールライン
        $spec5 = [10 => "サイズ", 11 => "収納サイズ", 12 => "総重量", 13 => "ダウン量", 14 => "付属品", 15 => "素材", 16 => "生産国", 34 => "防水性能", 35 => "PVCフリー"];
        //プラティパス
        $spec6 = [10 => "サイズ", 11 => "収納サイズ", 12 => "総重量", 13 => "最小重量", 14 => "付属品", 15 => "素材", 16 => "生産国", 17 => "jan", 18 => "リザーバー容量"];
        //その他一般
        $spec99 = [10 => "サイズ", 11 => "収納サイズ", 12 => "総重量", 13 => "最小重量", 14 => "付属品", 15 => "素材", 16 => "生産国", 17 => "jan"];

        /**
         * スペック情報があればスペック情報を記載
         */
        $blank_chk = FALSE;
        for ($i = 10; $i < 37; $i++) { //スペック情報があるかどうかチェック
            if ($i != 17 && $value[$i] != "") {
                $blank_chk = TRUE;
                break;
            }
        }
        if ($blank_chk !== FALSE) {
            $postcontent .= <<<EOT
            <tr>
                <td colspan="2" style="text-align:center;font-weight:bold;background-color:lightgray">スペック</td>
            </tr>
            EOT;
            for ($i = 10; $i <= 37; $i++) {
                $postcontent .= "<tr>";
                switch ($cate_flag) {
                    case 0: //テント
                        if ($value[18] == "" && $value[19] == "" && $value[20] == "" && $value[21] == "") {
                        } else {
                            if ($i == 18) {
                                $postcontent .= <<<EOT
                                    </tr>
                                    <tr>
                                        <td colspan="2" style="font-weight:bolder;background:lightgray;text-align:center;">
                                        その他
                                        </td>
                                    </tr>
                                    <tr>
                                EOT;
                            }
                        }
                        if ($i != 17 && $i != 20 && $i < 22) {
                            if ($value[$i] != "") {
                                $postcontent .= <<<EOT
                                    <td style="text-align:center;font-weight:bold;min-width:100px">{$spec0[$i]}</td>
                                        <td style="text-align:center">{$value[$i]}</td>
                                    EOT;
                            }
                        } elseif ($i == 20) {
                            if ($value[$i] != "") {
                                $self_stand = "";
                                if ($value[$i] == 1) {
                                    $self_stand = "○";
                                } elseif ($value[$i] == 2) {
                                    $self_stand = "×";
                                }
                                $postcontent .= <<<EOT
                                        <td style="text-align:center;font-weight:bold">{$spec0[20]}</td>
                                            <td style="text-align:center">{$self_stand}</td>
                                    EOT;
                            }
                        }
                        if ($i == 22) {
                            if ($value[$i] != "") {
                                $product_link = get_permalink($skutoid[$value[$i]]);
                                $product_name = get_the_title($skutoid[$value[$i]]);

                                $postcontent .= <<<EOT
                                    <td style="text-align:center;font-weight:bold;min-width:100px">{$spec0[$i]}</td>
                                        <td style="text-align:center"><a href="{$product_link}">{$product_name}</a></td>
                                    EOT;
                            }
                        }
                        break;
                    case 1: //ストーブ
                        if ($i == 18) {
                            $postcontent .= <<<EOT
                                    </tr>
                                    <tr>
                                        <td colspan="2" style="font-weight:bolder;background:lightgray;text-align:center;">
                                        その他
                                        </td>
                                    </tr>
                                    <tr>
                                EOT;
                        }
                        if (($i >= 28 && $i <= 33) || $i <= 16) {
                            if ($value[$i] != "") {
                                $postcontent .= <<<EOT
                                    <td style="text-align:center;font-weight:bold">{$spec1[$i]}</td>
                                        <td style="text-align:center;">{$value[$i]}</td>
                                    EOT;
                            }
                        }
                        if ($i == 18) {
                            if ($value[$i] != "") {
                                $postcontent .= <<<EOT
                                    <td style="text-align:center;font-weight:bold">{$spec1[$i]}</td>
                                        <td style="text-align:center;">{$value[$i]}</td>
                                    EOT;
                            }
                        }
                        break;
                    case 2: //マットレス
                        if ($value[22] == "" && $value[23] == "" && $value[24] == "" && $value[25] == "" && $value[26] == "" && $value[27] == "") {
                        } else {
                            if ($i == 22) {
                                $postcontent .= <<<EOT
                                    </tr>
                                    <tr>
                                        <td colspan="2" style="font-weight:bolder;background:lightgray;text-align:center;">
                                        その他
                                        </td>
                                    </tr>
                                    <tr>
                                EOT;
                            }
                        }
                        if (($i >= 22 && $i <= 27) || $i <= 16) {
                            if ($value[$i] != "") {
                                $postcontent .= <<<EOT
                                            <td style="text-align:center;font-weight:bold">{$spec2[$i]}</td>
                                                <td style="text-align:center;">{$value[$i]}</td>
                                            EOT;
                            }
                        }
                        break;
                    case 3: //浄水器
                        if (($i >= 21 && $i <= 33) || ($i > 18 && $i <= 18)) {
                            //echo "あ";
                            if (($i == 21 && $value[21] != "") || ($i == 26 && $value[21] != "") || ($i == 31 && $value[21] != "")) {
                                if ($value[$i] != "") {
                                    $postcontent .= <<<EOT
                                        </tr>
                                        <tr>
                                            <td colspan="2" style="font-weight:bolder;background:lightgray;text-align:center;">
                                        EOT;
                                    switch ($i) {
                                        case 21:
                                            $postcontent .= "有効性";
                                            break;
                                        case 26:
                                            $postcontent .= "性能";
                                            break;
                                        case 31:
                                            $postcontent .= "特徴";
                                            break;
                                    }
                                    $postcontent .= <<<EOT
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="text-align:center;font-weight:bold">{$spec3[$i]}</td>
                                            <td style="text-align:center;">{$value[$i]}</td>
                                        EOT;
                                }
                            } else {
                                if ($value[$i] != "") {
                                    $postcontent .= <<<EOT
                                            <td style="text-align:center;font-weight:bold">{$spec3[$i]}</td>
                                                <td style="text-align:center;">{$value[$i]}</td>
                                            EOT;
                                }
                            }
                        } elseif ($i >= 10 && $i < 17) {
                            if ($value[$i] != "") {
                                $postcontent .= <<<EOT
                                            <td style="text-align:center;font-weight:bold">{$spec3[$i]}</td>
                                                <td style="text-align:center;">{$value[$i]}</td>
                                            EOT;
                            }
                        }
                        break;
                    case 4: //寝袋
                        if ($i == 18) {
                            $postcontent .= <<<EOT
                                    </tr>
                                        <tr>
                                            <td colspan="2" style="font-weight:bolder;background:lightgray;text-align:center;">
                                            その他
                                            </td>
                                        </tr>
                                        <tr>
                                    EOT;
                        }
                        if ($i <= 16) {
                            if ($value[$i] != "") {
                                $postcontent .= <<<EOT
                                                <td style="text-align:center;font-weight:bold">{$spec2[$i]}</td>
                                                    <td style="text-align:center;">{$value[$i]}</td>
                                                EOT;
                            }
                        } elseif ($i == 27) {
                            if ($value[$i] != "") {

                                $ondotai[0] = NULL;
                                $ondotai[1] = NULL;
                                $ondotai[2] = NULL;
                                $comfort[0] = NULL;
                                $limit[0] = NULL;
                                $risk[0] = NULL;
                                $comfort[1] = NULL;
                                $limit[1] = NULL;
                                $risk[1] = NULL;

                                $ondotai = explode("|", $value[$i]);
                                /*echo "<pre>";
                                    var_dump($ondotai);
                                    echo "</pre>";*/
                                if ($ondotai[0] == "EN") {
                                    $ondotai[0] = "EN規格";
                                }

                                $comfort = explode(":", $ondotai[1]);
                                $limit = explode(":", $ondotai[2]);
                                if ($comfort[1] != NULL) {
                                    $limit_t = str_replace("℃", "", $comfort[1]) - 1;
                                    if ($ondotai[3] != "") {
                                        $risk = explode(":", $ondotai[3]);
                                        $risk_t = str_replace("℃", "", $limit[1]) - 1;
                                    }
                                    if ($risk[0] != "") {

                                        $postcontent .= <<<EOT
                                            <td style="text-align:center;font-weight:bold">{$spec2[$i]}<br>($ondotai[0])</td>
                                                <td style="text-align:center;line-height: 1em;">
                                                    <table style="margin:0">
                                                        <tr>
                                                            <td style="border-bottom:0;background: rgb(0,130,3);background: linear-gradient(90deg, rgba(0,130,3,1) 0%, rgba(17,179,0,1) 100%);color:white;width:33.3%;border-right:1px solid white;font-weight:bolder;"><table style="border:0;margin:0;padding:0;width:100%;"><tr style="border:0;margin:0;padding:0;width:100%;"><td style="border:0;margin:0;padding:0;width:50%;padding-left:5px" align="left">{$comfort[0]}<br>Range</td><td style="border:0;margin:0;padding:0;width:50%;" align="right">〜{$comfort[1]}</td></tr></table></td><td style="background: rgb(255,121,0);background: linear-gradient(90deg, rgba(255,121,0,1) 0%, rgba(255,181,0,1) 100%);color:white;width:33.3%;border-right:1px solid white;font-weight:bolder;"><table style="border:0;margin:0;padding:0;width:100%;"><tr style="border:0;margin:0;padding:0;width:100%;"><td style="border:0;margin:0;padding:0;width:50%;padding-left:5px" align="left">{$limit[0]}<br>Range</td><td style="border:0;margin:0;padding:0;width:50%;" align="right">{$limit_t}〜{$limit[1]}</td></tr></table></td><td style="background: rgb(150,0,0);background: linear-gradient(90deg, rgba(150,0,0,1) 0%, rgba(255,0,0,1) 100%);color:white;width:33.3%;font-weight:bolder;"><table style="border:0;margin:0;padding:0;width:100%;"><tr style="border:0;margin:0;padding:0;width:100%;"><td style="border:0;margin:0;padding:0;width:50%;padding-left:5px" align="left">{$risk[0]}<br>Range</td><td style="border:0;margin:0;padding:0;width:50%;padding-right:5px" align="right">{$risk_t}〜{$risk[1]}</td></tr></table></td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            EOT;
                                    } else {
                                        $postcontent .= <<<EOT
                                            <td style="text-align:center;font-weight:bold">{$spec2[$i]}<br>(規格外)</td>
                                                <td style="text-align:center;line-height: 1em;">
                                                    <table  style="margin:0">
                                                        <tr>
                                                            <td style="border-bottom:0;background: rgb(0,130,3);background: linear-gradient(90deg, rgba(0,130,3,1) 0%, rgba(17,179,0,1) 100%);color:white;width:33.3%;border-right:1px solid white;font-weight:bolder;"><table style="border:0;margin:0;padding:0;width:100%;"><tr style="border:0;margin:0;padding:0;width:100%;"><td style="border:0;margin:0;padding:0;width:50%;padding-left:5px" align="left">{$comfort[0]}<br>Range</td><td style="border:0;margin:0;padding:0;width:50%;" align="right">〜{$comfort[1]}</td></tr></table></td><td style="background: rgb(255,121,0);background: linear-gradient(90deg, rgba(255,121,0,1) 0%, rgba(255,181,0,1) 100%);color:white;width:33.3%;border-right:1px solid white;font-weight:bolder;"><table style="border:0;margin:0;padding:0;width:100%;"><tr style="border:0;margin:0;padding:0;width:100%;"><td style="border:0;margin:0;padding:0;width:50%;padding-left:5px" align="left">{$limit[0]}<br>Range</td><td style="border:0;margin:0;padding:0;width:50%;" align="right">{$limit_t}〜{$limit[1]}</td></tr></table></td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            EOT;
                                    }
                                }
                            }
                        }
                        break;
                    case 5: //LED
                        if (
                            $i == 18
                        ) {
                            $postcontent .= <<<EOT
                                    </tr>
                                        <tr>
                                            <td colspan="2" style="font-weight:bolder;background:lightgray;text-align:center;">
                                            その他
                                            </td>
                                        </tr>
                                        <tr>
                                    EOT;
                        }
                        if (
                            $i <= 16
                        ) {
                            if ($value[$i] != "") {
                                $postcontent .= <<<EOT
                                                <td style="text-align:center;font-weight:bold">{$spec4[$i]}</td>
                                                    <td style="text-align:center;">{$value[$i]}</td>
                                                EOT;
                            }
                        }
                        if ($i == 26 || $i == 28 || $i == 30 || $i == 31) {
                            if ($value[$i] != "") {
                                $postcontent .= <<<EOT
                                                <td style="text-align:center;font-weight:bold">{$spec4[$i]}</td>
                                                    <td style="text-align:center;">{$value[$i]}</td>
                                                EOT;
                            }
                        }
                        if ($i == 34) {
                            if ($value[$i] != "") {
                                $proof = [4 => "IPX4", 5 => "IPX5", 6 => "IPX6", 7 => "IP67"];
                                $postcontent .= <<<EOT
                                                <td style="text-align:center;font-weight:bold">{$spec4[$i]}</td>
                                                    <td style="text-align:center;">{$proof[$value[$i]]}</td>
                                                EOT;
                            }
                        }
                        break;
                    case 6: //シールライン
                        if (
                            $i == 18
                        ) {
                            $postcontent .= <<<EOT
                                    </tr>
                                        <tr>
                                            <td colspan="2" style="font-weight:bolder;background:lightgray;text-align:center;">
                                            その他
                                            </td>
                                        </tr>
                                        <tr>
                                    EOT;
                        }
                        if (
                            $i <= 16
                        ) {
                            if ($value[$i] != "") {
                                $postcontent .= <<<EOT
                                                <td style="text-align:center;font-weight:bold">{$spec5[$i]}</td>
                                                    <td style="text-align:center;">{$value[$i]}</td>
                                                EOT;
                            }
                        }
                        if ($i == 35) {
                            if ($value[$i] == 1) {
                                $postcontent .= <<<EOT
                                                <td style="text-align:center;font-weight:bold">{$spec5[$i]}</td>
                                                    <td style="text-align:center;">○</td>
                                                EOT;
                            }
                        }
                        if ($i == 34) {
                            if ($value[$i] != "") {
                                $proof = [1 => "<strong>SPLASHPROOF:</strong>雨や軽い水濡れに耐えます。", 2 => "<strong>WATERPROOF:</strong>短時間の水没に耐え、水に落とした場合浮かびます。", 3 => "<strong>SUBMERSIBLE:</strong>水面下１メートルの水没に３０分間耐えます。"];
                                $postcontent .= <<<EOT
                                                <td style="text-align:center;font-weight:bold">{$spec5[$i]}</td>
                                                    <td style="text-align:center;">{$proof[$value[$i]]}</td>
                                                EOT;
                            }
                        }
                        break;
                    case 7: //プラティパス
                        if (
                            $i == 18
                        ) {
                            if ($value[$i] != "") {
                                $postcontent .= <<<EOT
                                    </tr>
                                        <tr>
                                            <td colspan="2" style="font-weight:bolder;background:lightgray;text-align:center;">
                                            その他
                                            </td>
                                        </tr>
                                        <tr>
                                        <td style="text-align:center;font-weight:bold">{$spec6[$i]}</td>
                                                    <td style="text-align:center;">{$value[$i]}</td>
                                    EOT;
                            }
                        }
                        if (
                            $i <= 16
                        ) {
                            if ($value[$i] != "") {
                                $postcontent .= <<<EOT
                                                <td style="text-align:center;font-weight:bold">{$spec6[$i]}</td>
                                                    <td style="text-align:center;">{$value[$i]}</td>
                                                EOT;
                            }
                        }
                        break;
                    case 99: //その他・一般
                        if ($i < 17) {
                            if ($value[$i] != "") {
                                $postcontent .= <<<EOT
                                    <td style="text-align:center;font-weight:bold;min-width:100px">{$spec99[$i]}</td>
                                        <td style="text-align:center">{$value[$i]}</td>
                                    EOT;
                            }
                        }
                        break;
                }
                $postcontent .= "</tr>";
            }
        } //スペック情報があるかどうか終了

        $postcontent .= "</table>";

        /**
         * 動画エリア作成
         */
        if ($value[36] != "") {
            $postcontent .= "<h3>動画</h3>";

            if (strpos($value[36], "|") !== "false") {
                $youtube = explode("|", $value[36]);
            } else {
                $youtube = $value[36];
            }
            if (!is_array($youtube)) {
                $postcontent .= <<<EOT
                <div class="product-movie"><iframe loading="lazy" width="560" height="315" src="https://www.youtube.com/embed/{$youtube}" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div>
                EOT;
            } else {
                $postcontent .= '<div class="product-movie">';
                for ($ii = 0; $ii < count($youtube); $ii++) {
                    $postcontent .= <<<EOT
                    <iframe loading="lazy" width="560" height="315" src="https://www.youtube.com/embed/{$youtube[$ii]}" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen style="margin-right:2px"></iframe>
                    EOT;
                }
                $postcontent .= '</div>';
            }
        }

        $postcontent .= "</p>";

        //description作成終了///////////////////////////////////////////////////////////////////////////////////////////////////////////



        /**
         * データベース書き込みエリア
         */
        $name2 = str_replace(array(" ", "　"), "-", $name);
        $name2 = str_replace("--", "-", $name2);
        if (mb_substr($name2, 0, 1) == "-") {
            $name2 = substr($name2, 1);
        }
        //echo $name2."<br>";
        if (strpos($name, '(P)') !== false || strpos($name, '（Ｐ）') !== false) {
            $name2 = urlencode("パーツ-" . $value[0]);
        } else {
            $name2 = urlencode($name2);
        }
        foreach ($wp_postmeta as $wp_postmetas) {
            if (!empty($wp_postmetas["post_id"])) {
                if ($wp_postmetas["post_id"] == $data_final[$value[0]]["ID"] && $wp_postmetas["meta_key"] == "_wp_old_slug") {
                    $temp_old_slug = $wp_postmetas["meta_value"];
                }
            } else {
                continue;
            }
        }
        if (strlen($name2) >= 200) { //商品名が長い場合は変換
            $name2 = "product-" . $data_final[$value[0]]["ID"];
            if ($name2 != $temp_old_slug) {
                //echo "slug名が違うので更新します。旧{$temp_old_slug}:新{$name2}<br>";
                $Insert[] = $db->insertDatabase("wp_postmeta", $data_final[$value[0]]["ID"], "_wp_old_slug", $name2);
            } else {
                //echo "slug名が同じなので更新しません。旧{$temp_old_slug}:新{$name2}<br>";
            }
        }

        /**
         * バリエーションありの商品の説明を追加
         */
        $vd_flag = FALSE;
        if ($simpleorvariable[$data_final[$value[0]]["ID"]][0] == "product_variation") {
            if (count($wp_postmeta2) > 0) {
                foreach ($wp_postmeta2 as $valval) {
                    if ($vd_flag === TRUE) continue;
                    if ($valval["post_id"] == $data_final[$value[0]]["ID"] && $valval["meta_key"] == "_variation_description") {
                        if (SYNC_DESCRIPTION === true) {
                            $update[] = $db->updateDatabase("wp_postmeta", "'" . $postcontent . "'", "meta_value", [$data_final[$value[0]]["ID"], "_variation_description"], ["post_id", "meta_key"]);
                        }
                        $vd_flag = TRUE;
                    }
                }
            } else {
                $Insert[] = $db->insertDatabase("wp_postmeta", $data_final[$value[0]]["ID"], "_variation_description", $postcontent);
            }
            $result = $db->Multi_searchDatabase("wp_postmeta", ["post_id", "meta_key"], [$data_final[$value[0]]["ID"], "_variation_description"]);

            $test_r[] = $data_final[$value[0]]["ID"] . "|" . count($result);

            if (count($result) == 0) {
                $Insert[] = $db->insertDatabase("wp_postmeta", $data_final[$value[0]]["ID"], "_variation_description", $postcontent);
            }
        }
        $vd_flag = FALSE;
        /**
         * バリエーションありの商品用の手続き終了
         */


        /**
         * シンプル商品の説明を追加
         */
        if (SYNC_DESCRIPTION === true) {
            if ($simpleorvariable[$data_final[$value[0]]["ID"]][0] == "product_variation") {
                $update[] = $db->updateDatabase("wp_posts", "", "post_excerpt", $data_final[$value[0]]["ID"], "ID");
                $update[] = $db->updateDatabase("wp_posts", "'" . $value[7] . "'", "post_excerpt", $simpleorvariable[$data_final[$value[0]]["ID"]][1], "ID");
            } else {
                $update[] = $db->updateDatabase("wp_posts", "'" . $value[7] . $postcontent . "'", "post_excerpt", $data_final[$value[0]]["ID"], "ID");
            }
            if (SYNC_PRICE === true) {
                $update[] = $db->updateDatabase("wp_postmeta", "'" . $value[39] . "'", "meta_value", [$data_final[$value[0]]["ID"], "_price"], ["post_id", "meta_key"]);
                $update[] = $db->updateDatabase("wp_postmeta", "'" . $value[39] . "'", "meta_value", [$data_final[$value[0]]["ID"], "_regular_price"], ["post_id", "meta_key"]);
            }
        }

        if ($value[37] == 1) { //EC販売がonか
            /**
             * オーダーの処理中を、在庫からマイナス
             */
            $num = 0;
            $stock = "";
            foreach ($product_id_order as $vv) {
                if ($vv[0] == $data_final[$value[0]]["ID"]) {
                    if ($num == 0) {
                        $stock = $value[1] - $vv[1];
                        $num++;
                    } else {
                        $stock = $stock - $vv[1];
                        $num++;
                    }
                } else {
                    $stock = $value[1];
                }
            }
            $update[] = $db->updateDatabase("wp_postmeta", "'" . $stock . "'", "meta_value", [$data_final[$value[0]]["ID"], "_stock"], ["post_id", "meta_key"]);
            if ($stock >= 1) {
                $update[] = $db->updateDatabase("wp_postmeta", "'instock'", "meta_value", [$data_final[$value[0]]["ID"], "_stock_status"], ["post_id", "meta_key"]);
            } elseif ($stock <= 0) {
                $update[] = $db->updateDatabase("wp_postmeta", "'outofstock'", "meta_value", [$data_final[$value[0]]["ID"], "_stock_status"], ["post_id", "meta_key"]);
            }
        } else { //EC販売なし
            /**
             * 在庫は0、在庫無しに
             */
            $update[] = $db->updateDatabase("wp_postmeta", "'0'", "meta_value", [$data_final[$value[0]]["ID"], "_stock"], ["post_id", "meta_key"]);
            $update[] = $db->updateDatabase("wp_postmeta", "'outofstock'", "meta_value", [$data_final[$value[0]]["ID"], "_stock_status"], ["post_id", "meta_key"]);
        }


        //商品名に（P）がある場合、(P)を削除
        if (strpos($name, '(P)') !== false || strpos($name, '（Ｐ）') !== false) {
            $name = preg_replace("/(P)|（Ｐ）/", "", $name);
            $name2 = preg_replace("/%28P%29|%EF%BC%88%EF%BC%B0%EF%BC%89/", "", $name2);
        }

        /**
         * 商品名をデータベースへ書き込み
         */
        if (SYNC_NAME === true) {
            $update[] = $db->updateDatabase("wp_posts", "'" . $name . "'", "post_title", $data_final[$value[0]]["ID"], "ID");
            $update[] = $db->updateDatabase("wp_posts", "'" . $name2 . "'", "post_name", $data_final[$value[0]]["ID"], "ID");
        }
        if ($value[5] == 1 && $value[38] != 3) {
            $publish = "publish";
            $ping_status = "open";
        } else {
            $publish = "trash";
            $ping_status = "closed";
        }
        $update[] = $db->updateDatabase("wp_posts", "'" . $publish . "'", "post_status", $data_final[$value[0]]["ID"], "ID");
        $update[] = $db->updateDatabase("wp_posts", "'" . $ping_status . "'", "ping_status", $data_final[$value[0]]["ID"], "ID");

        //$description[] = $value[7];

        if ($ex_count == count($csv_data)) {
            //$Insert[] = $db->insertDatabase("wp_postmeta", $data_final[$value[0]]["ID"], "_visibility", "visible");
            //date_default_timezone_set('Asia/Tokyo');
            //$datetime = date("Y-m-d H:i:s");
            //$error = error_get_last();
            //$debug = $name;
            //$debug = implode("|", $debug);
            //$wpdb->query($wpdb->prepare("INSERT INTO `cron_log` (`id`, `name`, `datetime`, `error`) VALUES (NULL,'商品情報を更新しました','{$datetime}',' {$debug}');"));
            //$pro_count = true;
            //$description = implode("\n",$description);
            //$wpdb->query($wpdb->prepare("INSERT INTO `cron_log` (`id`, `name`, `datetime`, `error`) VALUES (NULL,'商品情報を更新しました','{$datetime}','{$debug}');"));
        }
    }
}//終了2