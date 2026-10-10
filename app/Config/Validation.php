<?php

namespace Config;

use App\Enums\Audience;
use App\Enums\ErrorStatus;
use App\Enums\Tone;
use App\Validation\WritingRules;
use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Validation\StrictRules\CreditCardRules;
use CodeIgniter\Validation\StrictRules\FileRules;
use CodeIgniter\Validation\StrictRules\FormatRules;
use CodeIgniter\Validation\StrictRules\Rules;

class Validation extends BaseConfig
{
    // --------------------------------------------------------------------
    // Setup
    // --------------------------------------------------------------------

    /**
     * Stores the classes that contain the rules that are available.
     *
     * @var list<string>
     */
    public array $ruleSets = [
        Rules::class,
        FormatRules::class,
        FileRules::class,
        CreditCardRules::class,
        WritingRules::class,
    ];

    /**
     * Specifies the views that are used to display the errors.
     *
     * @var array<string, string>
     */
    public array $templates = [
        'list'   => 'CodeIgniter\Validation\Views\list',
        'single' => 'CodeIgniter\Validation\Views\single',
    ];

    // --------------------------------------------------------------------
    // Rule groups (used by name from controllers, e.g. `$validation->run($data, 'login')`)
    // --------------------------------------------------------------------

    /** @var array<string, string> */
    public array $login = [
        'username' => 'required|string|max_length[64]',
        'password' => 'required|string|max_length[128]',
    ];

    /** @var array<string, array<string, string>> */
    public array $login_errors = [
        'username' => ['required' => 'Vui lòng nhập tên đăng nhập.', 'max_length' => 'Tên đăng nhập quá dài.', 'string' => 'Tên đăng nhập không hợp lệ.'],
        'password' => ['required' => 'Vui lòng nhập mật khẩu.', 'max_length' => 'Mật khẩu quá dài.', 'string' => 'Mật khẩu không hợp lệ.'],
    ];

    /** @var array<string, string> */
    public array $refresh = [
        'refreshToken' => 'required|string|max_length[512]',
    ];

    /** @var array<string, array<string, string>> */
    public array $refresh_errors = [
        'refreshToken' => ['required' => 'Thiếu refresh token.', 'max_length' => 'Refresh token không hợp lệ.', 'string' => 'Refresh token không hợp lệ.'],
    ];

    /** @var array<string, string> */
    public array $createWriting = [];

    /** @var array<string, array<string, string>> */
    public array $createWriting_errors = [];

    /** @var array<string, string> */
    public array $errorDecision = [];

    /** @var array<string, array<string, string>> */
    public array $errorDecision_errors = [
        'status' => ['required' => 'Vui lòng chọn trạng thái.', 'in_list' => 'Trạng thái không hợp lệ.', 'string' => 'Trạng thái không hợp lệ.'],
    ];

    public function __construct()
    {
        parent::__construct();

        $api = config(Api::class);

        $this->createWriting = [
            'text'     => "required|string|max_length[{$api->maxTextLength}]|min_words[{$api->minWords}]",
            'tone'     => 'required|string|' . Tone::inList(),
            'audience' => 'required|string|' . Audience::inList(),
        ];

        $this->createWriting_errors = [
            'text' => [
                'required'   => 'Vui lòng nhập bài viết.',
                'string'     => 'Bài viết không hợp lệ.',
                'max_length' => "Bài viết tối đa {$api->maxTextLength} ký tự.",
                'min_words'  => "Bài viết cần tối thiểu {$api->minWords} từ.",
            ],
            'tone'     => ['required' => 'Vui lòng chọn tone giọng.', 'string' => 'Tone giọng không hợp lệ.', 'in_list' => 'Tone giọng không hợp lệ.'],
            'audience' => ['required' => 'Vui lòng chọn đối tượng.', 'string' => 'Đối tượng không hợp lệ.', 'in_list' => 'Đối tượng không hợp lệ.'],
        ];

        $this->errorDecision = ['status' => 'required|string|' . ErrorStatus::inList()];
    }
}
