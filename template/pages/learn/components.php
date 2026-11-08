<?php use ZealPHP\App; $active = $active ?? 'learn/components'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 13,
      'title'    => 'Layouts & Components',
      'subtitle' => 'Stop copy-pasting HTML. Extract shared structure into reusable templates.',
      'prev'     => ['slug' => 'learn/store', 'title' => 'Sharing State'],
      'next'     => ['slug' => 'learn/react-vs-php', 'title' => 'React vs PHP'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Why copy-pasting layouts across pages is a maintenance nightmare',
      'Use App::render() to render a page inside a shared layout',
      'Build your own components — cards, callouts, anything — and compose them with App::component()',
      'How template variables flow from handler to view',
    ]]); ?>

    <h2>The problem</h2>
    <p>
      You have three pages. Each one needs the same <code>&lt;head&gt;</code>, navigation bar, and footer.
      Right now, you're copy-pasting that boilerplate into every file. When you change the nav, you
      change it in three places. When you have 20 pages, you change it in 20 places.
    </p>
    <p>That's not sustainable. You need a <strong>layout</strong>.</p>

    <h2>Step 1: Create a master layout</h2>
    <p>A layout is just a PHP template that wraps your page content. Create <a href="https://github.com/sibidharan/zealphp/blob/master/template/_master.php" target="_blank"><code>template/_master.php</code></a>:</p>
    <pre><code class="language-php-template">&lt;!doctype html&gt;
&lt;html lang="en"&gt;
&lt;head&gt;
  &lt;meta charset="utf-8"&gt;
  &lt;title&gt;&lt;?= htmlspecialchars($title ?? 'My App') ?&gt;&lt;/title&gt;
  &lt;link rel="stylesheet" href="/css/site.css"&gt;
&lt;/head&gt;
&lt;body&gt;
  &lt;nav&gt;...your nav here...&lt;/nav&gt;
  &lt;main&gt;
    &lt;?= App::component('/pages/' . $page) ?&gt;
  &lt;/main&gt;
  &lt;footer&gt;...&lt;/footer&gt;
&lt;/body&gt;
&lt;/html&gt;</code></pre>

    <h2>Step 2: Use it from your page</h2>
    <p>Now your <code>public/about.php</code> becomes three lines:</p>
    <pre><code class="language-php">&lt;?php use ZealPHP\App;
App::render('/_master', ['title' =&gt; 'About', 'page' =&gt; 'about']);</code></pre>
    <p>
      <code>App::render()</code> loads <code>template/_master.php</code>, extracts the variables
      (<code>$title</code> and <code>$page</code>) into scope, and executes it. The master template
      then renders the page content with a nested <code>App::component('/pages/about')</code>, which
      returns the page's markup for <code>&lt;?= ?&gt;</code> to print. <code>App::render()</code> is for the
      page itself; <code>App::component()</code> is for markup you put inside it. A leading <code>/</code>
      means the path is absolute from <code>template/</code>.
    </p>
    <p>
      Change the nav once in <code>_master.php</code> — every page picks it up. That's the
      power of a layout.
    </p>

    <h2>Building reusable components</h2>
    <p>
      The same pattern works for any reusable HTML. Think of a component as a
      <strong>stencil with holes</strong> — you lay the stencil down and fill in the holes
      with different data each time.
    </p>
    <pre><code class="language-php-template">&lt;!-- template/components/_card.php --&gt;
&lt;?php $variant ??= 'default'; ?&gt;
&lt;article class="card card-&lt;?= htmlspecialchars($variant) ?&gt;"&gt;
  &lt;h3&gt;&lt;?= htmlspecialchars($title) ?&gt;&lt;/h3&gt;
  &lt;p&gt;&lt;?= $body ?&gt;&lt;/p&gt;
&lt;/article&gt;</code></pre>
    <p>Use it in any template:</p>
    <pre><code class="language-php-template">&lt;?= App::component('card', [
    'title'   =&gt; 'Fast',
    'body'    =&gt; '117k requests per second',
    'variant' =&gt; 'highlight',
]) ?&gt;</code></pre>
    <p>
      <code>App::component('card')</code> looks in <code>template/components/</code> for <code>card.php</code>,
      then <code>_card.php</code>. It returns the markup and never echoes it, so you print it with
      <code>&lt;?= ?&gt;</code>, concatenate it, or return it from a route.
      No class, no JSX, no bundler. A component is just a PHP file that echoes HTML with variables.
    </p>

    <?php App::render('/components/_before_after', [
      'id' => 'react-php',
      'before_label' => 'React',
      'after_label'  => 'ZealPHP',
      'before' => '<pre><code class="language-jsx">function Card({ title, body, variant = "default" }) {
  return (
    &lt;article className={`card card-${variant}`}&gt;
      &lt;h3&gt;{title}&lt;/h3&gt;
      &lt;p&gt;{body}&lt;/p&gt;
    &lt;/article&gt;
  );
}

// Requires: React, JSX transpiler, bundler, hydration</code></pre>',
      'after' => '<pre><code class="language-php-template">&lt;?php $variant ??= \'default\'; ?&gt;
&lt;article class="card card-&lt;?= $variant ?&gt;"&gt;
  &lt;h3&gt;&lt;?= htmlspecialchars($title) ?&gt;&lt;/h3&gt;
  &lt;p&gt;&lt;?= $body ?&gt;&lt;/p&gt;
&lt;/article&gt;

// Requires: nothing. It\'s just PHP.</code></pre>',
    ]); ?>

    <h2>How variables flow</h2>
    <p>When you call <code>App::render('/_master', ['title' =&gt; 'About', 'page' =&gt; 'about'])</code>:</p>
    <ol>
      <li>ZealPHP loads <code>template/_master.php</code></li>
      <li>The array values are extracted as local variables: <code>$title = 'About'</code>, <code>$page = 'about'</code></li>
      <li>The template runs with those variables in scope</li>
      <li>Output is captured and sent to the browser</li>
    </ol>
    <p>Default values use PHP's null coalescing: <code>$variant ??= 'default'</code> sets a fallback
      when the caller doesn't pass <code>variant</code>.</p>

    <?php App::render('/components/_tryit', ['title' => 'Live demo: render in action', 'body' => <<<HTML
      <p>This page you're reading is a layout rendered with <code>App::render()</code>. The "You will learn" box, this "Try it" block, the nav, the sidebar — each is a separate component template rendered inside it.</p>
      <p>See both methods in action: <a class="lesson-chip" href="/api/learn/demo/render" target="_blank">Open render demo →</a> <a class="lesson-chip" href="/api/learn/demo/render-to-string" target="_blank">Open component demo →</a></p>
HTML]); ?>

    <?php App::render('/components/_deepdive', [
      'title' => 'Preview: the rest of the rendering API',
      'body'  => '<p>You\'ll use these in later lessons, but you can preview them now:</p>
<table class="preview-table">
  <thead><tr><th>Method</th><th>Returns</th><th>Use when</th><th></th></tr></thead>
  <tbody>
    <tr><td><code>render()</code></td><td>the result (echoed too)</td><td>A page or a response — this lesson</td><td><a class="lesson-chip" href="/api/learn/demo/render" target="_blank">Open →</a></td></tr>
    <tr><td><code>component()</code></td><td>markup, never echoed</td><td>Composing pieces; htmx fragments — <a href="/learn/notes">Lesson 18</a></td><td><a class="lesson-chip" href="/api/learn/demo/render-to-string" target="_blank">Open →</a></td></tr>
    <tr><td><code>render(..., stream: true)</code></td><td>Generator</td><td>SSR streaming — <a href="/learn/ai-chat">Lesson 20</a></td><td><a class="lesson-chip" href="/api/learn/demo/render-stream" target="_blank">Open →</a></td></tr>
  </tbody>
</table>
<p>The streaming demo is the most visual — watch 12 rows arrive one by one over ~2 seconds. Each <code>yield</code> flushes a chunk to the browser immediately.</p>',
    ]); ?>

    <?php App::render('/components/_concept_check', [
      'id'       => 'comp1',
      'question' => 'What does App::component(\'card\', [\'title\' => \'Hi\']) do?',
      'correct'  => 'a',
      'explain'  => 'App::component() loads template/components/_card.php, extracts the array as local variables, executes the template, and returns the markup without echoing it. Print it with &lt;?= ?&gt;. App::render() is the one that echoes.',
      'options'  => [
        'a' => 'Returns the rendered markup without echoing it',
        'b' => 'Echoes the rendered HTML to the response',
        'c' => 'Creates a new Card class instance',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      '<code>App::render(\'/_master\', [...])</code> wraps pages in a shared layout',
      '<code>&lt;?= App::component(\'card\', [...]) ?&gt;</code> composes pieces — it returns markup and never echoes',
      'Components are PHP files that echo HTML with variables — no framework overhead',
      'Variables are extracted from the render array into the template\'s scope',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/store"
         hx-get="/api/learn/page?slug=learn/store" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/store">← Sharing State</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/react-vs-php"
         hx-get="/api/learn/page?slug=learn/react-vs-php" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/react-vs-php">React vs PHP →</a>
    </div>
  </article>
</div>
