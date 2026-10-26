<?php

namespace App\Mail;

use App\Models\Newsrepo;
use Illuminate\Support\Facades\Log;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MailSend extends Mailable
{
    use Queueable, SerializesModels;

    // 2026/10/22 添付ファイル
    protected $attach_files = [];

    /**
     * Create a new message instance.
     *
     * @param  array  $files  2026/10/22 添付ファイル [['path','name','mime'], ...]
     * @return void
     */
    public function __construct($name,  $comment, $files = [])
    {
        $this->name = $name;
        // $this->email = $email;
        $this->comment = $comment;
        $this->attach_files = $files;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        Log::info('App_Mail build START');

        //間庭税理士事務所からお知らせがあります。システムをみてください
        // $data["body"] = "間庭税理士事務所からお知らせがあります。システムをみてください";

        // 2026/10/22 画像は本文に埋め込み(view側でembedData)、PDFは添付
        $images = [];
        foreach ($this->attach_files as $file) {
            if (strpos($file['mime'], 'image/') === 0) {
                $images[] = $file;
            } else {
                $this->attach($file['path'], [
                    'as'   => $file['name'],
                    'mime' => $file['mime'],
                ]);
            }
        }

        Log::info('App_Mail build END');

        return $this->view('newsrepo.contact') // どのテンプレートを呼び出すか
                    ->subject('税理士法人 間庭・飯田合同事務所からお知らせ')
                    ->from('system@arkhe-eco.com', '税理士法人 間庭・飯田合同事務所事務局')
                    ->with([
                        'name' => $this->name,
                        'comment' => $this->comment,
                        'images' => $images,
                    ]);

    }
}
