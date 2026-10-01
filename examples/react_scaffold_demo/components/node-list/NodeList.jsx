import {useState, useEffect} from "react";
import {Table, Column, HeaderCell, Cell} from 'rsuite-table';
import {Form, SelectPicker} from 'rsuite';

export const NodeList = ({endpoint, theme}) => {
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
  const ucfirst = (word) => word.charAt(0).toUpperCase() + word.slice(1)
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
  return (
    <>
      {response?.exposed_filters?.length && (
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
      <Table data={response.rows}
             className={'rs-theme-' + theme}
             onSortColumn={sortColumn}
             rowHeight={60}
             sortColumn={sort ? sort : response?.exposed_sorts[0]?.field_identifier}
             sortType={sortType}
      >
        {response?.rows?.length && Object.keys(response.rows[0]).map((col) => {
          return <Column
            key={col}
            flexGrow={Math.floor(10 / Object.keys(response.rows[0]).length)}
            minWidth={100}
            sortable={isSortable(col)}
          >
            <HeaderCell>{ucfirst(col)}</HeaderCell>
            <Cell rowKey={'nid'} dataKey={col}>{(row) => rawHtml(row[col])}</Cell>
          </Column>
        })}
      </Table>
    </>
  );
}
