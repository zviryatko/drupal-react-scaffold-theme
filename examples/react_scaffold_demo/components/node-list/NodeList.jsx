import {useState, useEffect} from "react";
import {Table, Column, HeaderCell, Cell} from 'rsuite-table';
import {Form, SelectPicker} from 'rsuite';

// Narrow screens get cards instead of the table (a table with six columns does not fit a phone).
const useMediaQuery = (query) => {
  const [matches, setMatches] = useState(() => window.matchMedia?.(query).matches ?? false)
  useEffect(() => {
    const list = window.matchMedia?.(query)
    if (!list) return
    const update = () => setMatches(list.matches)
    update()
    if (list.addEventListener) list.addEventListener('change', update)
    else list.addListener?.(update)
    return () => {
      if (list.removeEventListener) list.removeEventListener('change', update)
      else list.removeListener?.(update)
    }
  }, [query])
  return matches
}

export const NodeList = ({endpoint, theme}) => {
  const compact = useMediaQuery('(max-width: 40rem)')
  const [response, setResponse] = useState([]);
  const [sort, setSort] = useState(null)
  const [sortType, setSortType] = useState('desc')
  const [filters, setFilters] = useState({})
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const fetch = () => {
    let params = filters
    if (sort) {
      params.sort_by = sort
    }
    if (sortType) {
      params.sort_order = sortType.toUpperCase()
    }
    apiClient(endpoint, params)
      .then((response) => {
        setResponse(response)
        setLoading(false)
      })
      .catch(setError)
  }
  useEffect(fetch, [sort, sortType, filters]);
  const sortColumn = (col, order) => {
    setSortType(order)
    setSort(col)
  }
  const applyFilters = (key) => {
    return (value) => {
      setFilters({...filters, [key]: value})
    }
  }
  const clearFilter = (key) => {
    return () => {
      setFilters({...filters, [key]: null})
    }
  }
  const isSortable = (col) => {
    return response?.exposed_sorts?.filter(sort => sort?.field_identifier === col).length > 0
  }
  // Header text from the JSON key: `modal_edit` -> `Modal edit`.
  const ucfirst = (word) => { const text = word.replace(/_/g, ' '); return text.charAt(0).toUpperCase() + text.slice(1) }
  const getOptions = (options) => {
    const entries = Object.entries(options)
    return entries.map((data) => {
      return {value: data[0], label: data[1]}
    })
  }
  if (loading) {
    return <div>{Drupal.t('Loading...')}</div>;
  }
  if (error) {
    return <div>{Drupal.t('Error loading nodes')}</div>;
  }
  const rows = response?.rows || []
  const columns = rows.length ? Object.keys(rows[0]) : []
  const sorts = response?.exposed_sorts || []
  const activeSort = sort || sorts[0]?.field_identifier || ''
  return (
    <>
      {response?.exposed_filters?.length > 0 && (
        <Form>
          {response.exposed_filters.map((filter) =>
            <Form.Group key={filter.identifier}>
              <Form.ControlLabel>{filter.label}</Form.ControlLabel>
              {!filter?.options && (
                <Form.Control
                  onChange={applyFilters(filter.identifier)}
                  onReset={applyFilters(filter.identifier)}
                  name={filter.identifier}/>
              )}
              {filter?.options && (
                <SelectPicker
                  onClean={clearFilter(filter.identifier)}
                  onSelect={applyFilters(filter.identifier)}
                  name={filter.identifier}
                  data={getOptions(filter.options)}/>
              )}
              {filter?.description && (
                <Form.HelpText>{filter?.description}</Form.HelpText>
              )}
            </Form.Group>
          )}
        </Form>
      )}
      {compact ? (
        <>
          {sorts.length > 0 && (
            <div className="node-list__sort">
              <label htmlFor="node-list-sort">{Drupal.t('Sort by')}</label>
              <select id="node-list-sort" value={activeSort} onChange={(e) => sortColumn(e.target.value, sortType)}>
                {sorts.map((item) => <option key={item.field_identifier} value={item.field_identifier}>{item.label}</option>)}
              </select>
              <button type="button"
                      aria-label={sortType === 'asc' ? Drupal.t('Ascending') : Drupal.t('Descending')}
                      onClick={() => sortColumn(activeSort, sortType === 'asc' ? 'desc' : 'asc')}>
                {sortType === 'asc' ? '↑' : '↓'}
              </button>
            </div>
          )}
          <ul className="node-list__cards">
            {rows.map((row, index) => (
              <li className="node-list__card" key={row.nid ?? index}>
                <dl>
                  {columns.map((col) => (
                    <div key={col}>
                      <dt>{ucfirst(col)}</dt>
                      <dd>{rawHtml(row[col])}</dd>
                    </div>
                  ))}
                </dl>
              </li>
            ))}
          </ul>
        </>
      ) : (
        <Table data={rows}
               autoHeight
               className={'rs-theme-' + theme}
               onSortColumn={sortColumn}
               rowHeight={60}
               sortColumn={activeSort}
               sortType={sortType}
        >
          {columns.map((col) => (
            <Column
              key={col}
              flexGrow={col === 'title' ? 3 : 1}
              minWidth={100}
              sortable={isSortable(col)}
            >
              <HeaderCell>{ucfirst(col)}</HeaderCell>
              <Cell rowKey={'nid'} dataKey={col}>{(row) => rawHtml(row[col])}</Cell>
            </Column>
          ))}
        </Table>
      )}
    </>
  );
}
