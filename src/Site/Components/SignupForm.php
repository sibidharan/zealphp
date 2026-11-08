<?php

declare(strict_types=1);

namespace ZealPHP\Site\Components;

use ZealPHP\Component\Action;
use ZealPHP\Component\Component;
use ZealPHP\Component\Model;

/**
 * Reactive components demo: input binding with live validation. `username` is
 * validated as you type; `email` and `plan` ride along with Save.
 */
final class SignupForm extends Component
{
    private const TAKEN = ['admin', 'root', 'zeal', 'zealphp'];

    protected string $tag = 'form';
    protected ?string $submit = 'save';
    protected array $attributes = ['class' => 'lc-demo lc-signup'];

    #[Model(live: true, rules: 'required|min:3|max:20|alpha_dash')]
    public string $username = '';

    #[Model(rules: 'required|email|max:120')]
    public string $email = '';

    #[Model(rules: 'in:free,pro')]
    public string $plan = 'free';

    #[Model]
    public bool $terms = false;

    public bool $saved = false;

    protected function rules(): array
    {
        return ['terms' => 'accepted'];
    }

    protected function messages(): array
    {
        return ['terms.accepted' => 'Please accept the terms.'];
    }

    protected function updatedUsername(string $value): void
    {
        $this->saved = false;
        if (in_array(strtolower($value), self::TAKEN, true)) {
            $this->addError('username', 'That username is taken.');
        }
    }

    #[Action]
    public function save(): void
    {
        $this->validate();
        if (in_array(strtolower($this->username), self::TAKEN, true)) {
            $this->addError('username', 'That username is taken.');
            return;
        }
        $this->saved = true;
        $this->dispatch('signup-saved', ['username' => $this->username]);
    }

    #[Action]
    public function startOver(): void
    {
        $this->username = '';
        $this->email = '';
        $this->plan = 'free';
        $this->terms = false;
        $this->saved = false;
        $this->clearErrors();
    }

    public function render(): mixed
    {
        return $this->view('components/live/signup');
    }
}
