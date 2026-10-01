import { useEffect, useMemo, useRef, useState } from 'react';

const DEBOUNCE = 300;
const RESERVED = ['page', 'sort_by', 'sort_order'];

// Read the initial state from the page URL so a filtered view can be shared.
const readUrl = () => Object.fromEntries(new URLSearchParams(window.location.search));

// Text filters have no options, select filters have `options`, grouped filters
// (e.g. numeric ranges) have `group_items`.
const filterType = (filter) => {
  if (filter.group_items && Object.keys(filter.group_items).length) return 'group';
  if (filter.options && Object.keys(filter.options).length) return 'select';
  return 'text';
};

const selectOptions = (filter) => {
  if (filterType(filter) === 'group') {
    return Object.entries(filter.group_items).map(([value, item]) => ({ value, label: item.title }));
  }
  return Object.entries(filter.options).map(([value, label]) => ({ value, label }));
};

const Card = ({ recipe }) => (
  <li className="recipe-explorer__card">
    <a className="recipe-explorer__link" href={recipe.url}>
      <span className="recipe-explorer__media">
        {recipe.image && <img src={recipe.image} alt="" loading="lazy" width="600" height="400"/>}
        {recipe.category && <span className="recipe-explorer__category">{recipe.category}</span>}
      </span>
      <span className="recipe-explorer__body">
        <span className="recipe-explorer__title">{recipe.title}</span>
        <span className="recipe-explorer__meta">
          {recipe.difficulty && (
            <span className={'recipe-explorer__badge recipe-explorer__badge--' + recipe.difficulty}>
              {recipe.difficulty}
            </span>
          )}
          {recipe.prep_time != null && (
            <span className="recipe-explorer__time">{Drupal.t('@min min', { '@min': recipe.prep_time })}</span>
          )}
        </span>
        {/* Summary is rendered by Drupal (text_trimmed formatter), so it is already filtered markup. */}
        <span className="recipe-explorer__summary" dangerouslySetInnerHTML={{ __html: recipe.summary }}/>
      </span>
    </a>
  </li>
);

const Pager = ({ pager, onPage }) => {
  if (!pager?.total_pages || pager.total_pages < 2) return null;
  const current = pager.current_page;
  return (
    <nav className="recipe-explorer__pager" aria-label={Drupal.t('Pagination')}>
      <button type="button" disabled={current === 0} onClick={() => onPage(current - 1)}>
        {pager.options?.tags?.previous || Drupal.t('Previous')}
      </button>
      {Array.from({ length: pager.total_pages }, (_, i) => (
        <button
          type="button"
          key={i}
          aria-current={i === current ? 'page' : undefined}
          aria-label={Drupal.t('Page @n', { '@n': i + 1 })}
          className={i === current ? 'is-active' : ''}
          onClick={() => onPage(i)}
        >{i + 1}</button>
      ))}
      <button type="button" disabled={current >= pager.total_pages - 1} onClick={() => onPage(current + 1)}>
        {pager.options?.tags?.next || Drupal.t('Next')}
      </button>
    </nav>
  );
};

export const RecipeExplorer = ({ endpoint, heading }) => {
  const [data, setData] = useState(null);
  const [query, setQuery] = useState(readUrl);
  const [text, setText] = useState(() => ({}));
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const requestId = useRef(0);

  const update = (patch) => setQuery((q) => {
    const next = { ...q, ...patch };
    // Anything but a page change goes back to the first page.
    if (!('page' in patch)) delete next.page;
    Object.keys(next).forEach((k) => (next[k] === '' || next[k] == null) && delete next[k]);
    return next;
  });

  // Fetch whenever the query changes; ignore out-of-order responses.
  useEffect(() => {
    const id = ++requestId.current;
    setLoading(true);
    apiClient(endpoint, { ...query })
      .then((response) => {
        if (id !== requestId.current) return;
        setData(response);
        setError(false);
        setLoading(false);
      })
      .catch(() => id === requestId.current && (setError(true), setLoading(false)));
    const qs = new URLSearchParams(query).toString();
    window.history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
  }, [endpoint, query]);

  // Debounce free text inputs so we do not request on every keystroke.
  useEffect(() => {
    const timers = Object.entries(text).map(([key, value]) =>
      setTimeout(() => query[key] !== value && update({ [key]: value }), DEBOUNCE));
    return () => timers.forEach(clearTimeout);
  }, [text]);

  const filters = data?.exposed_filters || [];
  const sorts = data?.exposed_sorts || [];
  const sortBy = query.sort_by || sorts[0]?.field_identifier || '';
  const sortOrder = query.sort_order || (sortBy === 'created' ? 'DESC' : 'ASC');
  const active = useMemo(
    () => Object.keys(query).filter((k) => !RESERVED.includes(k)).length > 0, [query]);

  const reset = () => { setText({}); setQuery({}); };

  if (!data) {
    return <p className="recipe-explorer__status" role="status">{error ? Drupal.t('Error loading recipes') : Drupal.t('Loading…')}</p>;
  }

  return (
    <section className={'recipe-explorer__inner' + (loading ? ' is-loading' : '')} aria-busy={loading}>
      <form className="recipe-explorer__filters" role="search" onSubmit={(e) => e.preventDefault()}>
        {filters.map((filter) => {
          const type = filterType(filter);
          const id = 'recipe-explorer-' + filter.identifier;
          return (
            <div className="recipe-explorer__field" key={filter.identifier}>
              <label htmlFor={id}>{filter.label}</label>
              {type === 'text' ? (
                <input
                  id={id}
                  type="search"
                  placeholder={filter.description}
                  value={text[filter.identifier] ?? query[filter.identifier] ?? ''}
                  onChange={(e) => setText({ ...text, [filter.identifier]: e.target.value })}
                />
              ) : (
                <select id={id} value={query[filter.identifier] || ''}
                        onChange={(e) => update({ [filter.identifier]: e.target.value })}>
                  <option value="">{Drupal.t('Any')}</option>
                  {selectOptions(filter).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>
              )}
            </div>
          );
        })}
        {sorts.length > 0 && (
          <div className="recipe-explorer__field">
            <label htmlFor="recipe-explorer-sort">{Drupal.t('Sort by')}</label>
            <div className="recipe-explorer__sort">
              <select id="recipe-explorer-sort" value={sortBy}
                      onChange={(e) => update({ sort_by: e.target.value, sort_order: undefined })}>
                {sorts.map((s) => <option key={s.field_identifier} value={s.field_identifier}>{s.label}</option>)}
              </select>
              <button type="button" className="recipe-explorer__order"
                      aria-label={sortOrder === 'ASC' ? Drupal.t('Ascending') : Drupal.t('Descending')}
                      onClick={() => update({ sort_by: sortBy, sort_order: sortOrder === 'ASC' ? 'DESC' : 'ASC' })}>
                {sortOrder === 'ASC' ? '↑' : '↓'}
              </button>
            </div>
          </div>
        )}
        {active && <button type="button" className="recipe-explorer__reset" onClick={reset}>{Drupal.t('Reset')}</button>}
      </form>

      <h2 className="recipe-explorer__heading">{heading}</h2>
      <p className="recipe-explorer__count" role="status" aria-live="polite">
        {Drupal.formatPlural(Number(data.pager.total_items), '1 recipe found', '@count recipes found')}
      </p>

      {error && <p className="recipe-explorer__status">{Drupal.t('Error loading recipes')}</p>}
      {data.rows.length === 0 && !error && (
        <p className="recipe-explorer__status">{Drupal.t('No recipes match these filters.')}</p>
      )}
      <ul className="recipe-explorer__grid">
        {data.rows.map((recipe) => <Card key={recipe.url} recipe={recipe}/>)}
      </ul>
      <Pager pager={data.pager} onPage={(page) => update({ page })}/>
    </section>
  );
};
