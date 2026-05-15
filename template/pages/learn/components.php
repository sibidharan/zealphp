<?php use ZealPHP\App; $active = $active ?? 'learn/components'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 4,
      'title'    => 'Layouts & Components',
      'subtitle' => 'Stop copy-pasting HTML. Extract shared structure into reusable templates.',
      'prev'     => ['slug' => 'learn/first-page', 'title' => 'Your First Page'],
      'next'     => ['slug' => 'learn/htmx', 'title' => 'Forms & htmx'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Why copy-pasting layouts across pages is a maintenance nightmare',
      'Use App::render() to compose pages from reusable templates',
      'Build your own components — cards, callouts, anything',
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
    <pre><code class="language-php">&lt;!doctype html&gt;
&lt;html lang="en"&gt;
&lt;head&gt;
  &lt;meta charset="utf-8"&gt;
  &lt;title&gt;&lt;?= htmlspecialchars($title ?? 'My App') ?&gt;&lt;/title&gt;
  &lt;link rel="stylesheet" href="/css/site.css"&gt;
&lt;/head&gt;
&lt;body&gt;
  &lt;nav&gt;...your nav here...&lt;/nav&gt;
  &lt;main&gt;
    &lt;?php App::render('/pages/' . $page); ?&gt;
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
      then renders the page content with a nested <code>App::render('/pages/about')</code>.
    </p>
    <p>
      Change the nav once in <code>_master.php</code> &mdash; every page picks it up. That's the
      power of a layout.
    </p>

    <h2>Building reusable components</h2>
    <p>
      The same pattern works for any reusable HTML. Think of a component as a
      <strong>stencil with holes</strong> &mdash; you lay the stencil down and fill in the holes
      with different data each time.
    </p>
    <pre><code class="language-php">&lt;!-- template/components/_card.php --&gt;
&lt;?php $variant ??= 'default'; ?&gt;
&lt;article class="card card-&lt;?= htmlspecialchars($variant) ?&gt;"&gt;
  &lt;h3&gt;&lt;?= htmlspecialchars($title) ?&gt;&lt;/h3&gt;
  &lt;p&gt;&lt;?= $body ?&gt;&lt;/p&gt;
&lt;/article&gt;</code></pre>
    <p>Use it anywhere:</p>
    <pre><code class="language-php">App::render('/components/_card', [
    'title'   =&gt; 'Fast',
    'body'    =&gt; '117k requests per second',
    'variant' =&gt; 'highlight',
]);</code></pre>
    <p>No class, no JSX, no bundler. A component is just a PHP file that echoes HTML with variables.</p>

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
      'after' => '<pre><code class="language-php">&lt;?php $variant ??= \'default\'; ?&gt;
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
      <p>This page you're reading is built with nested <code>App::render()</code> calls. The "You will learn" box, this "Try it" block, the nav, the sidebar &mdash; each is a separate component template being rendered with <code>App::render()</code>.</p>
      <p>See the render method in action: <a class="lesson-chip" href="/api/learn/demo/render" target="_blank">Open render demo &rarr;</a></p>
HTML]); ?>

    <?php App::render('/components/_deepdive', [
      'title' => 'Two more render methods (you\'ll learn them later)',
      'body'  => '<p><code>App::render()</code> echoes directly. But sometimes you need the HTML as a string &mdash; to return it from an API, cache it, or embed it in another response. That\'s <code>App::renderToString()</code>, which you\'ll use in <a href="/learn/notes">Lesson 8 (Personal Notes)</a> for htmx fragment responses.</p><p>There\'s also <code>App::renderStream()</code> for streaming HTML chunks &mdash; you\'ll see it in <a href="/learn/ai-chat">Lesson 9 (AI Chat)</a>. For now, <code>App::render()</code> handles everything.</p>',
    ]); ?>

    <?php App::render('/components/_concept_check', [
      'id'       => 'comp1',
      'question' => 'What does App::render(\'/components/_card\', [\'title\' => \'Hi\']) do?',
      'correct'  => 'b',
      'explain'  => 'App::render() loads the template file, extracts the array as local variables, executes the template, and echoes the output.',
      'options'  => [
        'a' => 'Returns the HTML as a string',
        'b' => 'Echoes the rendered HTML to the response',
        'c' => 'Creates a new Card class instance',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      '<code>App::render(\'/_master\', [...])</code> wraps pages in a shared layout',
      'Components are PHP files that echo HTML with variables &mdash; no framework overhead',
      'Variables are extracted from the render array into the template\'s scope',
      'For now you only need <code>render()</code> &mdash; two more methods come in later lessons',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/first-page"
         hx-get="/api/learn/page?slug=learn/first-page" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/first-page">&larr; Your First Page</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/htmx"
         hx-get="/api/learn/page?slug=learn/htmx" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/htmx">Forms &amp; htmx &rarr;</a>
    </div>
  </article>
</div>
