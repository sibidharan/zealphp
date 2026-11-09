# Components phase 4: composition (design for review)

Status: **accepted** (2026-11-09). All four parts are implemented: §1 isolation, §2 child identity with `#[Reactive]`, §3 events and §4 slots. See [Decisions](#decisions) for what was chosen and where the implementation differs from the proposal. Builds on [the server-driven components design](2026-11-06-server-driven-components.md), whose phase 4 row asks for child components, updates of other components from one action, and component events.

## Where we are

`App::component()` throws when a reactive component renders inside another one. Stateless components already nest anywhere. The throw exists because nesting breaks three things today:

1. **Request isolation.** A component's request collects every input inside its root (`hx-include="closest [data-zc]"`, or the root `<form>` without JavaScript). A child's `_zc` snapshot and fields would be posted with the parent's, and the two `_zc` inputs collide. HTML also forbids a `<form>` inside a `<form>`, and the default root tag for interactive components is `form`.
2. **Child identity.** A parent re-render builds its children from props again. Each child's own state lives only in its snapshot in the page, so the server can't rebuild it: every parent action would reset its children.
3. **Updating a sibling.** An action in one component (add to cart) often changes another one on the same page (the cart badge). Push covers other tabs and visitors, but within a page there's no way to ask a sibling to refresh.

## Proposal

### 1. Isolation: every component owns a form, by id

Each reactive component renders an empty, detached form, and every control it binds points at it with the HTML `form` attribute:

```html
<div id="cart-1" data-zc="cart">
  <form id="cart-1-zc" method="post" action="/_zeal/c/cart/_refresh" hidden></form>
  <input type="hidden" name="_zc" value="…" form="cart-1-zc">
  <input name="coupon" form="cart-1-zc" …>
  <button formaction="/_zeal/c/cart/apply" form="cart-1-zc" …>Apply</button>

  <div id="line-7" data-zc="cart-line">        <!-- a child, its own form -->
    <form id="line-7-zc" … hidden></form>
    <input type="hidden" name="_zc" value="…" form="line-7-zc">
    <input name="qty" form="line-7-zc" …>
  </div>
</div>
```

- A form's `elements` are exactly the controls associated with it, wherever they sit in the DOM. So `new FormData(form)`, a plain no-JS submit, and htmx's `hx-include="#cart-1-zc"` (htmx serialises a form through its `elements`) all send one component's fields and nothing from its children or parent.
- Forms are never nested: the per-component forms are empty siblings.
- The root tag stops mattering for behaviour. Default it to `div`; a component that sets `$tag = 'form'` keeps working when it isn't nested, and nesting one throws with a clear message.

**BC:** markup changes for every component (the hidden form and a `form` attribute on each bound control), but templates don't: `$c->model()`, `$c->action()` and the snapshot input already come from the framework. Custom binders get the form id from a new `Component::formId()`.

### 2. Child identity: kept by the page, not rebuilt

The parent's snapshot records which children it rendered: key → child id and a hash of the props it passed.

On a parent re-render, `App::component(Child::class, $props, key: $k)` checks that list.

| Case | Server renders | Page does |
|---|---|---|
| New key (not in the parent's snapshot) | the child, mounted from `$props` | inserts it |
| Same key, same props hash | a placeholder: `<div id="line-7" data-zc-keep></div>` | keeps the existing child element as it is: state, focus, typed input |
| Same key, props changed | a placeholder plus a signed **props token** for the child | keeps the child, then refreshes it with the new props (see below) |
| Key missing from the new render | nothing | removes it |

Keeping the element:
- **htmx 4:** morphing already keeps elements by id; the placeholder carries `hx-preserve`. In htmx 4 that only takes effect when the old element has it too, so child roots always carry it.
- **htmx 2:** `hx-preserve` on both sides.
- **The vanilla client:** swaps the placeholder for the live element.

**Props flow one way and only on purpose.** A child's props are its initial state. A parent re-render doesn't overwrite a child's state unless the child marks a property `#[Reactive]`. Only those properties go into the props hash and the props token. The token is signed with a purpose of its own, and is bound to the child's id and the session. The child's `_refresh` request carries its own snapshot plus the token; the server applies only the `#[Reactive]` properties from it, then re-renders. This keeps Livewire's rule ("reactive props are explicit") without trusting the browser with the values.

### 3. Events: one component asks others to refresh

```php
#[Action]
public function add(int $productId): void
{
    Cart::add($productId);
    $this->dispatch('cart-updated');   // exists today: Zeal-Events / HX-Trigger
}

final class CartBadge extends Component
{
    #[On('cart-updated')]
    public function sync(): void { $this->count = Cart::count(); }
}
```

- A component with `#[On('cart-updated')]` methods renders the events it listens for.
- `HtmxBinder` emits this on the root:

  ```
  hx-trigger="cart-updated from:body"
  hx-post="/_zeal/c/cart-badge/_on"
  hx-vals='{"_zc_event":"cart-updated"}'
  ```

  htmx already turns `HX-Trigger` response events into DOM events, so this needs no client code. The vanilla client reads `Zeal-Events` and does the same.
- The endpoint's new `_on` action runs the matching `#[On]` methods (detail as the argument), then re-renders. Same snapshot, authorisation and validation path as any action.
- Events stay ids-and-names only. Each listener refreshes through its own snapshot and session, the rule push already follows. Push and events then compose: `Component::invalidate('cart')` reaches other tabs, and `dispatch('cart-updated')` reaches this page.

### 4. Slots: markup owned by the parent

A reactive child can take slot content from the parent (`App::component(Modal::class, [...], slot: $markup)`). The slot belongs to the parent's render, so the child can't re-create it on its own refresh.

- The child renders its slot inside `<div id="{child}-slot" data-zc-slot hx-preserve>`.
- On the child's own updates the server emits the region empty, and the page keeps the existing slot DOM.
- When the parent re-renders, the slot comes with it, because it's part of the parent's markup.

### 5. Limits

- **Depth:** at most 8 levels of reactive nesting, and the existing 64 KB cap applies to each snapshot. The parent's child list adds roughly 40 bytes per child.
- **Children inside a parent's `#[Model]` loop** (one child per row) need stable keys. Rendering children without `key:` inside a reactive parent throws, because positional keys would hand one row's state to another after a reorder.
- **Push** keeps working per component; a parent and its children have separate ids and topics.

## Decisions

Answered 2026-11-09:

1. **Isolation:** detached forms (§1), not a client-side filter.
2. **Child props:** explicit `#[Reactive]`, not always re-rendering children.
3. **Cross-component updates:** `#[On]` listeners and events (§3), not multi-component responses.
4. **Order:** isolation, child identity, events, reactive props, slots.

**Implementation note: child identity is server-side, not client-side.** §2 proposed that a parent re-render emit placeholders the page keeps, plus a signed props token for `#[Reactive]` values. Building it showed a simpler design that needs neither:

- Each child renders a copy of its signed snapshot into every enclosing component's form (`_zc_kids[{id}]`), so a parent's request carries its children's current state.
- When the parent re-renders, `App::component()` rebuilds each child from that snapshot, verified and used only for its own id, instead of mounting it from props. It then applies the parent's new values to the child's `#[Reactive]` properties only.
- The response is the whole parent, fully rendered by the server. So it behaves the same with htmx 2, htmx 4, `ZealJsBinder` and without JavaScript, with no `hx-preserve` or morph semantics involved, and no props token.
- A child's own snapshot records its ancestors' form ids, so a child's own update keeps its copies in their forms.
- No-JS parent actions flash the carried snapshots too, so the page after the redirect shows every child's state.

**Implementation note: events (§3).** Listeners are hidden `<button type="button" form="{id}-zc">` elements rather than spans: htmx 4 serialises an element's own form, but for an `hx-include`d form it collects only descendants, which a detached form has none of. A component doesn't hear its own events (an `hx-trigger` filter, and the same rule in `zeal.js`), which also rules out self-loops. Detail-less events go out as `{}` in `HX-Trigger`, because htmx 4's `trigger()` throws on a `null` detail.

**Implementation note: slots (§4), server-side too.** Instead of a client-preserved slot region, a reactive child's `slot` prop is kept in its signed snapshot (`sl`). The child's own updates re-render it from there, and a parent re-render replaces it. So, like child identity, it works the same on every client. The cost is snapshot size, which counts towards the 64 KB cap. The rule that comes with it: a slot can't contain reactive components (their state would be frozen in the stored markup), and that's refused at render. `slot` is a reserved property name.

## Done when

- A cart example (cart, lines as children, badge as a sibling) works with htmx 2, htmx 4 and without JavaScript.
- Tests prove the following:
  - A parent action keeps each child's state and its focused input.
  - A child's request never carries the parent's fields, and vice versa.
  - Reordering keyed children moves their state with them.
  - A forged props token is refused.
  - `#[On]` methods run only for the events they declare.
- PHPStan level 10, and the `src/Component` mutation gate.
