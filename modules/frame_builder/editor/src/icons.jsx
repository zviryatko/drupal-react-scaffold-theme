// Icons of the list of blocks. A component names one in its *.component.yml:
//   thirdPartySettings: { frame_builder: { icon: columns } }
// The value is a name of this set, or CSS classes of an icon font the theme loads ("bi bi-star"). Fields of the page,
// views and Drupal blocks each have one icon for all of them; a component without an icon gets the default.
const paths = {
  component: 'M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z M4 7.5 12 12l8-4.5 M12 12v9',
  field: 'M3 6h18v12H3z M7 10.5h5 M7 14h10',
  library: 'M6 4h12v16l-6-4-6 4z',
  paragraph: 'M10 4h9 M13 4v16 M17 4v16 M10 4a4 4 0 0 0 0 8h3',
  view: 'M9 6h11 M9 12h11 M9 18h11 M4.5 6h.01 M4.5 12h.01 M4.5 18h.01',
  block: 'M4 4h7v7H4z M13 4h7v7h-7z M4 13h7v7H4z M13 13h7v7h-7z',
  columns: 'M4 4h16v16H4z M12 4v16',
  flex: 'M3 7h5v10H3z M10 7h4v10h-4z M16 7h5v10h-5z',
  stack: 'M4 4.5h16v4H4z M4 10.5h16v4H4z M4 16.5h16v3H4z',
  image: 'M4 5h16v14H4z M4 16l4.5-4.5 4 4 3-3L20 17 M15 9.5h.01',
  text: 'M5 6h14 M12 6v13 M9 19h6',
  card: 'M4 4h16v16H4z M4 11h16 M7.5 15h6',
  section: 'M3 5h18v14H3z M7 9.5h10 M7 14h7',
  tooltip: 'M4 5h16v10h-9l-4 4v-4H4z',
  table: 'M4 5h16v14H4z M4 10h16 M4 15h16 M10 5v14',
  search: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14z M20 20l-4-4',
  link: 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1 M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1',
  button: 'M4 8h16v8H4z M9 12h6',
  form: 'M4 5h16v5H4z M4 14h10v5H4z M17 16.5h3',
  video: 'M4 6h12v12H4z M16 10l4-2.5v9L16 14',
  list: 'M4 6h16 M4 12h16 M4 18h16',
  heading: 'M6 5v14 M18 5v14 M6 12h12',
  quote: 'M6 8h4v4a4 4 0 0 1-4 4 M14 8h4v4a4 4 0 0 1-4 4',
  map: 'M12 21s6-5.7 6-10.5a6 6 0 0 0-12 0C6 15.3 12 21 12 21z M12 12.5a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
  slider: 'M3 7h18v10H3z M7 12l-2 0 M19 12h-2 M10 20h.01 M14 20h.01',
  accordion: 'M4 5h16v4H4z M4 11h16v8H4z M8 7h.01',
};

/** Names of the built-in icons, for documentation and tests. */
export const iconNames = Object.keys(paths);

export function BlockIcon({ icon }) {
  // Classes of an icon font: more than one word, or not a name of the set.
  if (icon && !paths[icon] && /^[\w -]+$/.test(icon)) {
    return <i className={`frame-builder-block-icon ${icon}`} aria-hidden="true" />;
  }
  return (
    <svg className="frame-builder-block-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d={paths[icon] || paths.component} />
    </svg>
  );
}
