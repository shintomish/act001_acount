<?php

namespace App\Http\Controllers;

use Log;
use DateTime;
use App\Models\Organization;
use App\Models\User;
use App\Models\Book;
use App\Models\UploadUser;
use App\Models\Customer;
use App\Models\ControlUser;
use App\Models\ImageUpload;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
//use Illuminate\Support\Facades\Log;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * 顧客が特定できない利用者に割り当てるダミーの顧客ID。
     * 2026/09/04 追加。
     * 以前は customer_id = 1 (アルケーエコ = 運営会社) を割り当てていたため、
     * 設定不備の利用者に運営会社のデータが見えてしまう懸念があった。
     * customers テーブルには実体を作らず、この番号を「データ登録不明」として扱う。
     */
    const UNKNOWN_CUSTOMER_ID   = 999999;
    const UNKNOWN_CUSTOMER_NAME = 'データ登録不明';

    /**
     * 「データ登録不明」を表す、DBに保存しない Customer オブジェクトを返す。
     * 画面側は id / business_name / individual_class / foldername しか
     * 参照しないため、それらに安全な既定値を入れて返す。
     */
    public function unknown_customer()
    {
        $customer                   = new Customer();
        $customer->id               = self::UNKNOWN_CUSTOMER_ID;
        $customer->organization_id  = 0;
        $customer->business_name    = self::UNKNOWN_CUSTOMER_NAME;
        $customer->individual_class = 1;
        $customer->active_cancel    = 1;
        // 実在しないフォルダー名。ファイル一覧は必ず空になる。
        $customer->foldername       = 'folder' . self::UNKNOWN_CUSTOMER_ID;
        // 保存させない (exists=false のまま返す)
        return $customer;
    }

    /**
     * 利用者に紐づく顧客ID(customers.id)を、DBを更新せずに解決する。
     * 2026/09/04 追加。ログイン可否判定・利用者登録時の紐づけ作成で共用する。
     *
     * 優先順位
     *   (1) controlusers に有効な紐づけがある → その顧客ID
     *   (2) users.user_id が有効な顧客        → その顧客ID
     *   (3) いずれも無い                      → null (= データ登録不明)
     *
     * @param  int $u_id users.id
     * @return int|null  customers.id
     */
    public function resolve_customer_id($u_id)
    {
        $customer_id = DB::table('controlusers')
            ->join('customers', 'controlusers.customer_id', '=', 'customers.id')
            ->where('controlusers.user_id', $u_id)
            ->whereNull('controlusers.deleted_at')
            ->whereNull('customers.deleted_at')
            // `active_cancel` 1:契約 2:SPOT 3:解約
            ->where('customers.active_cancel', '!=', 3)
            ->orderBy('controlusers.customer_id', 'asc')
            ->value('customers.id');

        if (! empty($customer_id)) {
            return (int) $customer_id;
        }

        // controlusers に無い場合は users.user_id (所属顧客) を見る
        $user = User::find($u_id);
        if (is_null($user) || empty($user->user_id)) {
            return null;
        }

        $customer_id = Customer::where('id', $user->user_id)
            ->whereNull('deleted_at')
            // `active_cancel` 1:契約 2:SPOT 3:解約 → 解約は対象外
            ->where('active_cancel', '!=', 3)
            ->value('id');

        return empty($customer_id) ? null : (int) $customer_id;
    }

    /**
     * 顧客が特定できない利用者について、管理者へ警告ログとメールを送る。
     * 2026/09/04 追加。メール送信に失敗しても呼び出し元の処理は止めない。
     *
     * @param  \App\Models\User $user
     * @param  string             $context 呼び出し箇所の識別子
     */
    public function notify_unknown_customer($user, $context = '')
    {
        $detail = '[' . $context . '] 顧客が特定できない利用者を検出しました。'
            . ' users.id = '        . print_r($user->id, true)
            . ' / name = '          . print_r($user->name, true)
            . ' / email = '         . print_r($user->email, true)
            . ' / users.user_id = ' . print_r($user->user_id, true)
            . ' / login_flg = '     . print_r($user->login_flg, true);

        Log::warning($detail);

        try {
            $to = env('ADMIN_ALERT_MAIL', 'y-shintomi@aizen-sol.co.jp');
            \Illuminate\Support\Facades\Mail::raw(
                "顧客が特定できない利用者を検出しました。\n"
                . "利用者顧客管理(controlusers)の設定をご確認ください。\n\n"
                . $detail . "\n",
                function ($message) use ($to) {
                    $message->to($to)->subject('【要対応】顧客未設定の利用者を検出しました');
                }
            );
        } catch (\Throwable $e) {
            // メール送信失敗しても処理は止めない
            Log::error('notify_unknown_customer mail failed : ' . $e->getMessage());
        }
    }

    //--------------------------------------------------------------------------------------------------
    //-- システム関連
    //--------------------------------------------------------------------------------------------------

    /**
     * ログインユーザーのユーザー情報Userを取得する
     */
    public function auth_user_info()
    {
        Log::info('auth_user_info START');

        $id = auth::user()->id;
        $ret_val = User::find($id);

        // Log::debug('auth_user_info ret_val = ' . print_r(json_decode($ret_val),true));
        Log::info('auth_user_info END');
        return $ret_val;
    }

    /**
     * 選択された顧客IDからCustomer情報(フォルダー名)を取得する
     */
    public function auth_user_foldername($u_id)
    {
        Log::info('auth_user_foldername START');

        // $id = auth::user()->id;
        // $user = User::find($id);
        // $u_id = $user->user_id;

        $ret_val = Customer::where('id',$u_id)->first();

        // 2026/09/04 追加
        // 「データ登録不明」または実在しない顧客IDのときは、
        //   呼び出し元で null 参照エラーにならないようダミーを返す。
        if (is_null($ret_val)) {
            Log::warning('auth_user_foldername: customer not found. id = ' . print_r($u_id, true));
            $ret_val = $this->unknown_customer();
        }

        // Log::debug('auth_user_foldername Customer ret_val = ' . print_r(json_decode($ret_val),true));
        Log::info('auth_user_foldername END');
        return $ret_val;
    }

    /**
     * Customer情報を取得する
     */
    public function auth_customer_all()
    {
        Log::info('auth_customer_all START');

        $id = auth::user()->id;
        $user = User::find($id);

        $o_id = $user->organization_id;

        if($o_id == 0 ){
            $ret_val = Customer::all();
        }else{
            $ret_val = Customer::where('organization_id',$o_id)->get();
        }
// var_dump($ret_val);
// die;
        // Log::debug('auth_customer_all ret_val = ' . print_r(json_decode($ret_val),true));
        Log::info('auth_customer_all END');
        return $ret_val;
    }

    /**
     * Customer(複数レコード)情報を取得するControlUser
     */
    public function auth_customer_findrec()
    {
        Log::info('auth_customer_findrec START');

        $u_id = auth::user()->id;

        Log::info('auth_customer_findrec START $u_id = ' . print_r($u_id ,true));
        if($u_id == 10) {
            $ret_val = Customer::whereNull('deleted_at')
                            // `active_cancel` 1:契約 2:SPOT 3:解約',
                            ->where('active_cancel','!=', 3)
                            ->orderBy('customers.business_name', 'asc')
                            ->get();
            Log::info('auth_customer_findrec END $u_id = ' . print_r($u_id ,true));
            Log::info('auth_customer_findrec END');
            return $ret_val;
        }
        // 2024/09/02 アクア先頭対応
        // user 221 伊勢地 megu.tezu@gmail.com
        // user 172 白　兆章 mail0@hakuwriter.com (複数法人)
        // 102 153 168
        if($u_id == 172) {
            $ret_val = Customer::whereNull('deleted_at')
                            // `active_cancel` 1:契約 2:SPOT 3:解約',
                            ->where('active_cancel','!=', 3)
                            ->whereIn( 'customers.id', [102, 153, 168] ) // 102 153 168
                            ->orderBy('customers.memo_5', 'asc')    //memo_5
                            ->get();
            Log::info('auth_customer_findrec END $u_id = ' . print_r($u_id ,true));
            Log::info('auth_customer_findrec END');
            return $ret_val;
        }

        // 2023/10/25 解約は表示しない対応
        // $controlusers = ControlUser::where('user_id',$u_id)
        //     ->whereNull('deleted_at')
        //     ->get();
        $controlusers = ControlUser::select(
                'controlusers.id              as id'
                ,'controlusers.organization_id as organization_id'
                ,'controlusers.user_id         as user_id'
                ,'controlusers.customer_id     as customer_id'
                ,'users.id                     as users_id'
                ,'users.name                   as users_name'
                ,'customers.id                 as customers_id'
                ,'customers.individual_class   as individual_class'
                ,'customers.active_cancel      as active_cancel'
                ,'customers.business_name      as business_name'
                )

                ->leftJoin('users', function ($join) {
                    $join->on('controlusers.user_id', '=', 'users.id');
                })
                ->leftJoin('customers', function ($join) {
                    $join->on('controlusers.customer_id', '=', 'customers.id');
                })
                // `active_cancel` 1:契約 2:SPOT 3:解約',
                ->where('customers.active_cancel','!=', 3)
                ->where('controlusers.user_id','=',$u_id)
                ->whereNull('customers.deleted_at')
                ->whereNull('users.deleted_at')
                ->whereNull('controlusers.deleted_at')
                ->orderBy('customers.individual_class', 'asc')
                ->orderBy('controlusers.user_id', 'asc')
                ->orderBy('controlusers.customer_id', 'asc')
                ->get();

        // Log::debug('auth_customer_findrec count = ' . print_r($controlusers->count(),true));
        if($controlusers->count() > 0) {
            $ret_val = array();
            foreach ($controlusers as $controlusers2) {

                $customers = Customer::where('id',$controlusers2->customer_id)
                    ->orderBy('id', 'asc')
                    ->first();
                array_push($ret_val, $customers );
            }
        } else {
            // controlusers に行がない利用者の自動補完。
            // 2026/09/04 修正: 以前は無条件で customer_id = 1(アルケーエコ) を
            //   紐づけていたため、新規登録した利用者のアップロード先が
            //   常にアルケーエコになってしまっていた。
            //   users.user_id に保持されている所属顧客(customers.id)を優先する。
            $ret_val = array();

            $customer_id = auth::user()->user_id;
            $customers   = null;
            if (!empty($customer_id)) {
                $customers = Customer::where('id', $customer_id)
                    ->whereNull('deleted_at')
                    // `active_cancel` 1:契約 2:SPOT 3:解約 → 解約は対象外
                    ->where('active_cancel', '!=', 3)
                    ->first();
            }
            // 所属顧客が特定できない場合は「データ登録不明」(999999)。
            // 以前はここで customer_id = 1 (アルケーエコ) を割り当てていたため、
            // 設定不備の利用者に運営会社のデータが見えてしまう懸念があった。
            if (is_null($customers)) {
                Log::warning('auth_customer_findrec: customer not found. u_id = ' . print_r($u_id, true)
                    . ' / users.user_id = ' . print_r(auth::user()->user_id, true));
                $customer_id = self::UNKNOWN_CUSTOMER_ID;
                $customers   = $this->unknown_customer();
            }
            array_push($ret_val, $customers );

            // 既に同じ紐づけ(論理削除含む)が存在する場合は
            // 重複INSERTを行わない。存在しないときだけ1件だけ作成する。
            // ※これを行わないと画面表示のたびにゴミ行が量産される。
            // ControlUser は SoftDeletes 未使用のため、通常クエリで
            // 論理削除済み(deleted_at あり)の行も含めて重複を判定する。
            $exists = DB::table('controlusers')
                ->where('user_id', $u_id)
                ->where('customer_id', $customer_id)
                ->exists();

            if (! $exists) {
                $conusers = new ControlUser();
                $conusers->organization_id = $customers->organization_id;
                $conusers->user_id         = $u_id;
                $conusers->customer_id     = $customer_id;
                $conusers->save();               //  Inserts
            }
        }
        // Log::debug('auth_customer_findrec ret_val = ' . print_r($ret_val,true));
        Log::info('auth_customer_findrec END $u_id = ' . print_r($u_id ,true));
        Log::info('auth_customer_findrec END');
        return $ret_val;
    }

    /**
     * ログインユーザーの組織IDを取得する
     */
    public function auth_user_organization()
    {
        Log::info('auth_user_organization START');

        $organization_id = auth::user()->organization_id;
        $ret_val = Organization::find($organization_id);

        // Log::debug('auth_user_organization ret_val = ' . print_r(json_decode($ret_val),true));
        Log::info('auth_user_organization END');
        return $ret_val;
    }

    /**
     * ログインユーザーの組織オブジェクトを取得する
     */
    public function auth_user_organization_id()
    {
        Log::info('auth_user_organization_id START');

        $ret_val = auth::user()->organization_id;
        // Log::debug('auth_user_organization_id ret_val = ' . $ret_val);

        Log::info('auth_user_organization_id END');
        return $ret_val;
    }
    /**
    * 当月から加算された月を取得;
    * @return string
    */
    public function get_specify_month($mon): string
    {
        $date = Carbon::parse('now');

        return DATE_FORMAT($date->addMonth($mon),'m'); // $monヶ月後;
    }

    /**
    * 今月の1月前を取得 date("Y-m-d", strtotime("-1 month"));
    * @return string
    */
    public function get_sub_month(): string
    {
        // $date = Carbon::parse('now');
        // $date->subMonth();
        // 1ヶ月前
        $date = Carbon::parse('now');

        return DATE_FORMAT($date->subMonth(),'m');
    }
    /**
    * 今月の〇月前を取得
    * @return string
    */
    public function get_submonth($mon): string
    {
        $date = Carbon::parse('now');

        return DATE_FORMAT($date->subMonth($mon),'m');
    }

    /**
    * 決算月を取得
    * @return string
    */
    public function get_closing_month($strmon): string
    {
        if($strmon == '13') {
            $strmon = 12;
        }

        return $strmon;
    }
    /**
    * 基準月($strmon)の〇($mon)月前を取得
    * @return string
    */
    public function getbase_submonth($strmon, $mon): string
    {
        $date = Carbon::parse('now');
        $stryear = $date->year;
        $strday = 1;
        if($strmon == '13') {
            $strmon = 12;
        }
        $strbase = $stryear .'-'.$strmon.'-'.$strday;

        $datebase = Carbon::parse($strbase);

        return DATE_FORMAT($datebase->subMonth($mon),'m');
    }

    /**
    * 基準月($strmon)の〇($mon)月後を取得;
    * @return string
    */
    public function getbase_specify_month($strmon, $mon): string
    {
        $date = Carbon::parse('now');
        $stryear = $date->year;
        $strday = 1;
        if($strmon == '13') {
            $strmon = 12;
        }
        $strbase = $stryear .'-'.$strmon.'-'.$strday;

        $datebase = Carbon::parse($strbase);

        return DATE_FORMAT($datebase->addMonth($mon),'m'); // $monヶ月後;
    }

    /**
    * 今月の1月後を取得 date("Y-m-d", strtotime("1 month"));
    * @return string
    */
    public function get_add_month(): string
    {
        // $date = Carbon::parse('now');
        // $date->subMonth();
        // 1ヶ月前
        $date = Carbon::parse('now');

        return DATE_FORMAT($date->addMonth(1),'m');
    }
    /**
    * 今月の月を取得
    * @return string
    */
    public function get_now_month(): string
    {
        return DATE_FORMAT(Carbon::now(),'m');
    }
    /**
    * 今年の年を取得
    * @return string
    */
    public function get_now_year(): string
    {
        return DATE_FORMAT(Carbon::now(),'Y');
    }
    /**
    * 今年の年を取得2 Book
    * @return string
    */
    public function get_now_year2(): string
    {
        // $id = 1;
        // $ret_val = Book::find($id);
        // return $ret_val->nowyear;
        // Jsonに変更 2021/12/23 一旦作成
        // $jsonfile = storage_path() . "/app/userdata/year_info.json";
        // $year = 2022;
        // $status = false;
        // $arr = array(
        //     "res" => array(
        //         "info" => array(
        //             [
        //                 "year"       => $year,
        //                 "status"     => $status
        //             ]
        //         )
        //     )
        // );
        // $arr = json_encode($arr);
        // file_put_contents($jsonfile , $arr);
        // --------------------------------------

        // -----Jsonより取得  2021/12/23 -------
        $jsonfile = storage_path() . "/app/userdata/year_info.json";
        $jsonUrl = $jsonfile; //JSONファイルの場所とファイル名を記述
        if (file_exists($jsonUrl)) {
            $json = file_get_contents($jsonUrl);
            $json = mb_convert_encoding($json, 'UTF8', 'ASCII,JIS,UTF-8,EUC-JP,SJIS-WIN');
            $obj = json_decode($json, true);
            $obj = $obj["res"]["info"];
            foreach($obj as $key => $val) {
                $year   = $val["year"];
                $status = $val["status"];
            }
            // Log::info('client postUpload  jsonUrl OK');
        } else {
            $year = $this->get_now_year();
            // echo "データがありません";
            // Log::info('client postUpload  jsonUrl NG');
        }

        return $year;
    }

    /**
     * ImageUpload(1レコード)情報を取得する
     * 事業主が会計データをアップロードしないで３カ月以上過ぎた場合(1) 過ぎてない(0)
     */
    public function get_three_month($cus_id): string
    {
        Log::info('get_three_month START');

        // $u_id = auth::user()->id;
        // $user = User::find($id);
        // $u_id = $user->id;

        // $imageUpload = ImageUpload::where('id',$u_id)
        //             ->orderBy('created_at', 'desc')
        //             ->get();

        $ret_val = DB::table('imageuploads')
                    ->where('customer_id',$cus_id)
                    ->orderBy('updated_at', 'desc')
                    ->first();

        $str = "0";
        if (isset($ret_val)) {
            // $str = ( new DateTime($ret_val->created_at))->format('Y-m-d');
            // 3ヶ月前
            $date = new Carbon(now());
            $old = $date->subMonths(3);

            $latest = new Carbon($ret_val->updated_at);

            //未満
            iF($latest->lt($old)) {
                $str = "1";
            }
        }
        // Log::debug('auth_customer_findrec ret_val = ' . print_r(json_decode($ret_val),true));
        Log::info('get_three_month END');
        return $str;
    }
    /**
     * Convert bytes to more appropriate format e.g. MB,GB..
     * @param int $size
     * @return string
     */
    function convertfilesize($insize): string
    {
        if ($insize >= 1073741824) {
            $fileSize = round($insize / 1024 / 1024 / 1024,1) . ' GB';
        } elseif ($insize >= 1048576) {
            $fileSize = round($insize / 1024 / 1024,1) . ' MB';
        } elseif ($insize >= 1024) {
            $fileSize = round($insize / 1024,1) . ' KB';
        } else {
            $fileSize = $insize . ' bytes';
        }
        return $fileSize;
    }
    /**
     *
     */
    function to_string( $time, $format='H:i' )
    {
        Log::info('to_string START');

        if( is_null($time)  ) return null;

        $datetime = new DateTime('2001-01-01 ' . $time);

        Log::debug('datetime = ' . print_r($datetime,true));
        Log::debug('datetime = ' . print_r($datetime,true));

        Log::info('to_string END');
        return $datetime->format($format);
    }

    /**
     *
     */
    function to_time_format( $sec, $format='%02d:%02d:%02d' )
    {
        Log::info('to_time_format START');

        if( is_null($sec)  ) return null;
        Log::debug('$sec = ' . print_r($sec,true));

        $hours = floor( $sec / 3600 );
        $minutes = floor( ( $sec / 60 ) % 60 );
        $seconds = $sec % 60;
        $time_foramt = sprintf($format, $hours, $minutes, $seconds);

        Log::info('to_time_format END');
        return $time_foramt;
    }

    function seconds2hours( $sec )
    {
        if( is_null($sec)  ) return null;

        $hours   = floor( $sec / 3600 );
        $minutes = floor( ( $sec / 60 ) % 60 );
        $time    = $hours + $minutes / 60;

        return $time;
    }

    /**
     * 本日日付の年度を返す
     */
    public function get_fiscal_year($year = null, $month = null)
    {
        Log::info('get_fiscal_year START');

        if( is_null($year) || is_null($month) ){
            $date  = new DateTime();
            $year  = $date->format('Y');
            $month = $date->format('n');
        }

        $f_year = $year;
        if( 1<= $month && $month <= 3){
            // 1~3月の場合は前年となる
            $f_year = $f_year - 1;
        }

        Log::debug('[input year] = ' . $year . ' [input month] = ' . $month . ' [fiscal year] = ' . $year);
        Log::info('get_fiscal_year END');
        return $f_year;
    }


    /**
     * 指定年月の日付と曜日配列を取得する
     */
    function getDayOfWeeks( $year, $month )
    {
        Log::info('getDayOfWeeks START');

        $dayOfWeeks = array();
        $week = array( "日", "月", "火", "水", "木", "金", "土" );

        $sDate = new DateTime();
        $sDate->setDate($year,$month,1);
        $thisDate = clone $sDate;
        while(true) {
            if( $sDate->format('m') != $thisDate->format('m')  ){
                break;
            }

            $dayOfWeek = array(  'day' => $thisDate->format("j")
                               , 'day_of_week' => $week[$thisDate->format("w")] );
            array_push($dayOfWeeks, $dayOfWeek);

            $thisDate = $thisDate->modify('+1 days');
            Log::debug('newDate = ' . $thisDate->format('Y-m-d'));
        }

        foreach($dayOfWeeks as $week){
            Log::debug('retval : day=' . $week['day'] . ', day_of_week=' . $week['day_of_week']);
        }

        Log::info('getDayOfWeeks END');
        return $dayOfWeeks;
    }

    /**
     * 祝日チェック
     */
    function is_holiday($organization_id, $date)
    {
        Log::info('is_holiday START');
        Log::debug('$organization_id=' . $organization_id . ', $date=' . $date);

        // 存在チェック
        $exist = Holiday::whereNull('deleted_at')
                        ->where('organization_id', $organization_id)
                        ->where('date', $date)
                        ->exists();

        Log::debug('ret_val = ' . $exist);
        Log::info('is_holiday END');
        return $exist;
    }

    /**
     * 指定桁数で切り上げ
     */
    function ceil_plus($value, $precision = 1)
    {
        return round($value + 0.5 * pow(0.1, $precision), $precision, PHP_ROUND_HALF_DOWN);
    }

    /**
     * 指定桁数で切り捨て
     */
    function floor_plus($value, $precision = 1)
    {
        return round($value - 0.5 * pow(0.1, $precision), $precision, PHP_ROUND_HALF_UP);
    }

    /**
     * 小数点以下第1位で0.50.5単位で切り捨て
     */
    function floor_half($value)
    {
        Log::info('floor_half START');
        Log::debug('input value = ' . $value);

        $value_disit = 0.0;
        $ret_val = floor($value);
        if( 0.5 <= $value - floor($value) ){
            $ret_val += 0.5;
        }

        Log::debug('output value = ' . $ret_val);
        Log::info('floor_half END');
        return $ret_val;
    }

    //--------------------------------------------------------------------------------------------------
    //-- parameter テーブル関連
    //--------------------------------------------------------------------------------------------------

    /**
     * parameterテーブルからnameをキーにvalueを取得
     */
    public function get_param_value( $organization_id , $param_name )
    {
        Log::info('get_param_value START');

        $value = Parameter::whereNull('deleted_at')
                          ->where('organization_id', $organization_id)
                          ->where('name'           , $param_name)
                          ->value('value');

        Log::debug('organization_id = ' . $organization_id);
        Log::debug('param_name      = ' . $param_name);
        Log::debug('value           = ' . $value);

        Log::info('get_param_value END');
        return $value;
    }


}
