<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component\Fixtures;

use ZealPHP\Component\Action;
use ZealPHP\Component\Component;
use ZealPHP\Component\Model;

final class Signup extends Component
{
    protected string $tag = 'form';
    protected ?string $submit = 'save';

    #[Model(rules: 'required|email')]
    public string $email = '';

    #[Model(live: true, rules: 'required|min:3')]
    public string $username = '';

    #[Model]
    public bool $agree = false;

    #[Model]
    public array $tags = [];

    #[Model]
    public ?Size $size = null;

    #[Model(blur: true)]
    public int $age = 0;

    #[Model]
    public ?bool $newsletter = null;

    #[Model]
    public float $score = 0.0;

    #[Model]
    public string $bio = '';

    /** Not a #[Model]: the browser can't write it. */
    public bool $saved = false;

    public int $usernameChanges = 0;

    /** When true, the template omits the age control. */
    public bool $hideAge = false;

    protected function updatedUsername(string $value): void
    {
        $this->usernameChanges++;
        if ($value === 'taken') {
            $this->addError('username', 'That username is taken.');
        }
        if ($value === 'hook-validate') {
            $this->validate(['email']);
        }
    }

    #[Action]
    public function save(): void
    {
        $this->validate();
        $this->saved = true;
        $this->dispatch('signed-up', ['email' => $this->email]);
    }

    #[Action]
    public function leave(): void
    {
        $this->redirect('/bye');
    }

    public function render(): mixed
    {
        return $this->view('components/signup');
    }
}
