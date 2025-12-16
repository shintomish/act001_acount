<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Actlog;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ActlogMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // return $next($request);
        $response = $next($request);
        $this->actlog($request, $response -> getStatusCode());
        return $response;
    }

    // public function actlog($request, $status)
    // {
    //     $user = $request -> user();
    //     $data = [
    //         'user_id' => $user ? $user->id : null,
    //         'route' => Route::currentRouteName(),
    //         'url' => $request -> path(),
    //         'method' => $request -> method(),
    //         'status' => $status,
    //         'data' => count($request->toArray()) != 0 ? json_encode($request->toArray()) : null,
    //         'remote_addr' => $request -> ip(),
    //         'user_agent' => $request -> userAgent(),
    //     ];
    //     Actlog::create($data);
    // }

    // 2025/10/11
    // URLをハッシュ化して保存
    // 攻撃者がURLに非常に長い文字列（例：JNDI）を挿入するのを防ぐために、URL自体をハッシュ化して保存する方法です。
    // これにより、URL情報を完全に隠し、ログとして利用する際に長さやセキュリティリスクを避けることができます。

    // 2025/12/16
    // 2025/10/10 の Log4Shell 攻撃対応において、URL のハッシュ化対応は実施したが、
    // POST データおよび User-Agent に対する長さ制限とログ保存失敗時の例外抑止が不足していたため、
    // 後続の攻撃リクエストによりログ保存処理が例外を発生させた。
    // DB変更
    // url：SHA-256固定長 → CHAR(64)
    // data：攻撃前提 → LONGTEXT
    // user_agent：TEXT
    public function actlog($request, $status)
    {
        try {
            $user = $request->user();

            $data = [
                'user_id'     => $user ? $user->id : null,
                'route'       => Route::currentRouteName(),
                // URLはハッシュのみ
                'url'         => hash('sha256', $request->fullUrl()),
                'method'      => $request->method(),
                'status'      => $status,
                // data は必ず制限
                'data'        => $this->limit(
                                    json_encode($request->toArray()),
                                    10000
                                ),
                'remote_addr' => $this->limit($request->ip(), 45),
                'user_agent'  => $this->limit($request->userAgent(), 1000),
            ];

            Actlog::create($data);

        } catch (\Throwable $e) {
            // ログ失敗は握りつぶす（重要）
            Log::warning(
                'actlog insert failed',
                ['error' => $e->getMessage()]
            );
        }
    }

    //truncate ヘルパー
    private function limit($value, $length)
    {
        return $value === null
            ? null
            : mb_substr((string)$value, 0, $length);
    }
}
