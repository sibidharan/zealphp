<?php
use ZealPHP\App;
use ZealPHP\Component\Registry;
use ZealPHP\Site\Components\LikeCounter;
use ZealPHP\Site\Components\SignupForm;

$live = Registry::byClass(LikeCounter::class) !== null;
?>
<section class="section">
<div class="container lc-page">
<h1 class="section-title">Components</h1>
<p class="section-desc">Build pages from components: plain PHP templates that take props, and — when you need interactivity — PHP classes that keep state, run actions and bind form inputs. The server renders HTML; the browser swaps it in. No client-side framework, no JSON API to keep in sync, and pages still work with JavaScript turned off.</p>

<div class="callout info">
  <p>One call renders both kinds: <code>App::component('card', $props)</code>. A name registered with <code>App::components()</code> is a <strong>reactive</strong> component; any other name renders a <strong>stateless</strong> template from <code>template/components/</code>. The full guide is <a href="/docs/guide/components"><code>docs/components.md</code></a>; <code>App::render()</code>, streaming and fragments are in the <a href="/templates">Templates &amp; Rendering reference</a>.</p>
</div>

<h2 id="stateless" class="lc-mt-2">Stateless components</h2>
<p>A template in <code>template/components/</code> that takes props. Like a React function component without hooks: same props in, same HTML out. Pass child markup as the <code>slot</code> prop.</p>

<div class="lc-demo-grid">
  <div class="lc-panel">
    <p class="lc-panel-label">Rendered</p>
    <?= App::component('card', ['icon' => '⚛', 'title' => 'Reactive components', 'body' => 'State, actions and input binding — below.', 'href' => '#reactive']) ?>
  </div>
  <div>
<?php App::render('/components/_code', [
    'label' => 'a page template',
    'lang'  => 'html',
    'code'  => <<<'HTML'
<?= App::component('card', [
    'icon'  => '⚛',
    'title' => 'Reactive components',
    'body'  => 'State, actions and input binding — below.',
    'href'  => '#reactive',
]) ?>
HTML]); ?>
<?php App::render('/components/_code', [
    'label' => 'template/components/_card.php (excerpt)',
    'lang'  => 'html',
    'code'  => <<<'HTML'
<a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>" class="card">
  <div class="card-icon"><?= $icon ?></div>
  <h3><?= htmlspecialchars($title) ?></h3>
  <p><?= htmlspecialchars($body) ?></p>
</a>
HTML]); ?>
  </div>
</div>
<p>The name resolves to <code>components/card.php</code>, then <code>components/_card.php</code>; use <code>'ui/button'</code> for folders. Stateless components can be used anywhere, including inside reactive ones.</p>

<h2 id="reactive" class="lc-mt-2">Reactive components</h2>
<p>A PHP class. Its public properties are its state, <code>#[Action]</code> methods are what buttons call, and <code>#[Model]</code> properties bind to form inputs. Every request builds a fresh instance from a signed snapshot in the page, so workers keep nothing between requests.</p>

<?php if (!$live): ?>
<div class="callout warn">
  <p>The live demos are off on this server because <code>ZEALPHP_APP_KEY</code> isn't set. Run <code>php app.php key:generate</code>, export the key, and restart — or start with <code>php app.php --dev</code>.</p>
</div>
<?php endif; ?>

<h3 id="counter" class="lc-mt-2">A counter</h3>
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

<h3 id="end-to-end" class="lc-mt-2">From class to page</h3>
<p>The class and its view above are two of the files. Register the component once at boot, then render it with one line in any page template. The page only has to load htmx.</p>
<?php App::render('/components/_code', [
    'label' => 'route/components.php — registers it at boot',
    'code'  => <<<'PHP'
<?php
use ZealPHP\App;
use App\Components\Counter;

App::components([Counter::class]);
PHP]); ?>
<?php App::render('/components/_code', [
    'label' => 'public/counter.php — the URL /counter',
    'code'  => <<<'PHP'
<?php
use ZealPHP\App;

App::render('counter-page', ['title' => 'Counter']);
PHP]); ?>
<?php App::render('/components/_code', [
    'label' => 'template/counter-page.php — the page',
    'lang'  => 'html',
    'code'  => <<<'HTML'
<?php
use ZealPHP\App;
use App\Components\Counter;
use function ZealPHP\e;
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?= e($title) ?></title>
  <script src="https://unpkg.com/htmx.org@2.0.10" defer></script>
</head>
<body>
  <h1><?= e($title) ?></h1>
  <?= App::component(Counter::class, ['count' => 5], key: 'main') ?>
</body>
</html>
HTML]); ?>
<p>Start the server with <code>ZEALPHP_APP_KEY</code> set and open <code>/counter</code>. A route works the same: <code>$app-&gt;route('/counter', fn() =&gt; App::render('counter-page', ['title' =&gt; 'Counter']))</code>. With a shared layout, the htmx script goes in the layout once and any page template, partial or component template can call <code>App::component()</code>. <a href="/docs/guide/components#putting-it-on-a-page-end-to-end">The full walkthrough</a> also shows the HTML the browser receives.</p>

<h3 id="binding" class="lc-mt-2">Input binding and live validation</h3>
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

<h3 id="push" class="lc-mt-2">Live across tabs (push)</h3>
<p>One like count shared by every visitor. The component lists the topic it depends on; the action invalidates it. Every open page showing the component re-renders it, over one SSE connection per page. Open this page in a second tab and click Like there.</p>

<div class="lc-demo-grid">
  <div class="lc-panel">
    <p class="lc-panel-label">Live</p>
    <?= $live ? App::component(\ZealPHP\Site\Components\SharedLikes::class, [], key: 'demo') : '<p>Demo disabled.</p>' ?>
  </div>
  <div>
<?php App::render('/components/_code', [
    'label' => 'src/Site/Components/SharedLikes.php',
    'code'  => <<<'PHP'
final class SharedLikes extends Component
{
    public static ?Counter $likes = null;     // shared by all workers

    public function topics(): array
    {
        return ['demo:likes'];
    }

    #[Action]
    public function like(): void
    {
        self::$likes?->increment();
        Component::invalidate('demo:likes');   // every page showing it re-renders
    }

    public function render(): mixed
    {
        return $this->view('components/live/likes', ['likes' => self::$likes?->get() ?? 0]);
    }
}
PHP]); ?>
<?php App::render('/components/_code', [
    'label' => 'template/_master.php (once, before </body>)',
    'lang'  => 'html',
    'code'  => <<<'HTML'
<?= App::componentPushRoot() ?>
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
