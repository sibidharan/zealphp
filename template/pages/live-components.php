<?php
use ZealPHP\App;
use ZealPHP\Component\Registry;
use ZealPHP\Site\Components\LikeCounter;
use ZealPHP\Site\Components\SignupForm;

$live = Registry::byClass(LikeCounter::class) !== null;
?>
<section class="section">
<div class="container lc-page">
<h1 class="section-title">Live Components</h1>
<p class="section-desc">Interactive UI from PHP classes and plain PHP templates. The server owns the state and renders HTML; the browser swaps it in. No client-side framework, no JSON API to keep in sync, and the page still works with JavaScript turned off.</p>

<div class="callout info">
  <p>A component is a PHP class. Its public properties are its state, <code>#[Action]</code> methods are what buttons can call, and <code>#[Model]</code> properties bind to form inputs. Every request builds a fresh instance from a signed snapshot in the page, so workers keep nothing between requests. The full guide is <a href="/docs/guide/live-components"><code>docs/live-components.md</code></a>.</p>
</div>

<?php if (!$live): ?>
<div class="callout warn">
  <p>The live demos are off on this server because <code>ZEALPHP_APP_KEY</code> isn't set. Run <code>php app.php key:generate</code>, export the key, and restart — or start with <code>php app.php --dev</code>.</p>
</div>
<?php endif; ?>

<h2 id="counter" class="lc-mt-2">A counter</h2>
<p>Each button posts to the component endpoint, which restores the counter from its snapshot, runs <code>add()</code>, and returns the re-rendered component.</p>

<div class="lc-demo-grid">
  <div class="lc-panel">
    <p class="lc-panel-label">Live</p>
    <?= $live ? App::component(LikeCounter::class, ['count' => 3], key: 'demo') : '<p>Demo disabled.</p>' ?>
  </div>
  <div>
<?php App::render('/components/_code', [
    'label' => 'src/Site/Components/LikeCounter.php',
    'code'  => <<<'PHP'
final class LikeCounter extends Component
{
    protected string $tag = 'form';   // works without JS too
    public int $count = 0;

    #[Action]
    public function add(int $by = 1): void
    {
        $this->count = max(0, $this->count + $by);
    }

    public function render(): mixed
    {
        return $this->view('components/live/counter');
    }
}
PHP]); ?>
<?php App::render('/components/_code', [
    'label' => 'template/components/live/counter.php',
    'lang'  => 'html',
    'code'  => <<<'HTML'
<span><?= e($count) ?></span>
<button <?= $c->action('add', ['by' => -1]) ?>>−1</button>
<button <?= $c->action('add') ?>>+1</button>
HTML]); ?>
  </div>
</div>

<h2 id="binding" class="lc-mt-2">Input binding and live validation</h2>
<p><code>username</code> is <code>live</code>: it's validated as you type (try <code>ab</code>, <code>admin</code>, or <code>a b</code>). The field you're typing in is never replaced, so focus and cursor stay put. <code>email</code>, <code>plan</code> and the checkbox are deferred — they ride along when you press <strong>Sign up</strong>, which runs <code>save()</code>.</p>

<div class="lc-demo-grid">
  <div class="lc-panel">
    <p class="lc-panel-label">Live</p>
    <?= $live ? App::component(SignupForm::class, [], key: 'demo') : '<p>Demo disabled.</p>' ?>
  </div>
  <div>
<?php App::render('/components/_code', [
    'label' => 'src/Site/Components/SignupForm.php',
    'code'  => <<<'PHP'
final class SignupForm extends Component
{
    protected string $tag = 'form';
    protected ?string $submit = 'save';   // what submitting the form runs

    #[Model(live: true, rules: 'required|min:3|max:20|alpha_dash')]
    public string $username = '';

    #[Model(rules: 'required|email')]
    public string $email = '';

    #[Model(rules: 'in:free,pro')]
    public string $plan = 'free';

    #[Model]
    public bool $terms = false;

    public bool $saved = false;          // no #[Model]: the browser can't set it

    protected function rules(): array { return ['terms' => 'accepted']; }

    protected function updatedUsername(string $value): void
    {
        if ($value === 'admin') {
            $this->addError('username', 'That username is taken.');
        }
    }

    #[Action]
    public function save(): void
    {
        $this->validate();               // errors re-render next to the fields
        $this->saved = true;
        $this->dispatch('signup-saved', ['username' => $this->username]);
    }
}
PHP]); ?>
<?php App::render('/components/_code', [
    'label' => 'template/components/live/signup.php (excerpt)',
    'lang'  => 'html',
    'code'  => <<<'HTML'
<input <?= $c->model('username') ?>>
<?= $c->error('username') ?>

<select <?= $c->model('plan', false) ?>>
  <option value="free" <?= $c->selected('plan', 'free') ?>>Free</option>
  <option value="pro"  <?= $c->selected('plan', 'pro') ?>>Pro</option>
</select>

<input type="checkbox" <?= $c->model('terms') ?> <?= $c->checked('terms') ?>>
<button type="submit">Sign up</button>
HTML]); ?>
  </div>
</div>

<h2 id="how" class="lc-mt-2">How a request works</h2>
<ol class="lc-list">
  <li>The page renders the component with <code>App::component()</code>. The framework wraps the template output in the component's element and adds a hidden <code>_zc</code> field: the state, signed with <code>ZEALPHP_APP_KEY</code> and tied to the visitor's session.</li>
  <li>A button or live input posts the form fields to <code>/_zeal/c/{component}/{action}</code>.</li>
  <li>The endpoint checks the signature, session and version, builds a fresh component from the snapshot, applies <code>#[Model]</code> input, and runs the action.</li>
  <li>It re-renders the component and sends back its HTML, with a new snapshot. htmx swaps it in. Without JavaScript the browser gets a redirect back to the page, which shows the new state.</li>
</ol>

<h2 id="security" class="lc-mt-2">What the browser can and can't do</h2>
<ul class="lc-list">
  <li><strong>Change state directly:</strong> no. The snapshot is HMAC-signed; any edit is rejected with 400.</li>
  <li><strong>Reuse another visitor's snapshot:</strong> no. Snapshots are bound to the session; another session's page just reloads with its own state.</li>
  <li><strong>Re-send an older snapshot of their own:</strong> yes, until it expires (24 hours by default). Keep votes, attempts and claims in the database, not in component state.</li>
  <li><strong>Call any method:</strong> no. Only <code>#[Action]</code> methods, with typed scalar arguments. <code>authorize()</code> can refuse per action.</li>
  <li><strong>Write any property:</strong> no. Only <code>#[Model]</code> properties the last render showed a control for. Values are converted to the declared type; objects are never built from input.</li>
  <li><strong>Read state:</strong> yes. Snapshots are signed, not encrypted — keep ids in state, not secrets.</li>
  <li><strong>Post from another site:</strong> no. Cross-origin requests are refused using <code>Sec-Fetch-Site</code> / <code>Origin</code>.</li>
</ul>

<h2 id="clients" class="lc-mt-2">htmx is first-class, not required</h2>
<p>The endpoint speaks plain HTTP: form fields in, HTML out. A <em>binder</em> decides which attributes the template helpers print. <code>HtmxBinder</code> (the default) emits <code>hx-*</code> attributes on top of plain-form markup; <code>FormBinder</code> emits only the plain forms; you can write your own for another client.</p>
<?php App::render('/components/_code', [
    'label' => 'app.php',
    'code'  => <<<'PHP'
use ZealPHP\Component\{HtmxBinder, FormBinder};

App::components([LikeCounter::class, SignupForm::class]);
App::componentBinder(new HtmxBinder());   // the default; FormBinder for no-JS pages
PHP]); ?>

</div>
</section>
