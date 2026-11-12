import { NodeList } from '../NodeList';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

const response = {
  pager: { total_items: 2 },
  exposed_filters: [],
  exposed_sorts: [{ field_identifier: 'created', label: 'Authored on' }],
  rows: [
    { title: '<a href="/a">First</a>', author: 'Ann', modal_edit: '<a class="use-ajax" href="/a/edit">Edit in Modal</a>' },
    { title: '<a href="/b">Second</a>', author: 'Bob', modal_edit: '' },
  ],
};

const mockMedia = (matches) => {
  window.matchMedia = jest.fn().mockImplementation(() => ({
    matches, addEventListener: jest.fn(), removeEventListener: jest.fn(),
  }));
};

describe('<NodeList />', () => {
  beforeEach(() => {
    window.Drupal = {
      t: (s) => s,
      attachBehaviors: jest.fn(),
      detachBehaviors: jest.fn(),
    };
    window.once = { remove: jest.fn() };
    window.drupalSettings = {};
    global.apiClient = window.apiClient = jest.fn(() => Promise.resolve(response));
    // rawHtml() comes from the base theme helpers.
    window.rawHtml = global.rawHtml = (html) => <span dangerouslySetInnerHTML={{ __html: html }} />;
  });

  it('shows cards with readable labels on narrow screens', async () => {
    mockMedia(true);
    render(<NodeList endpoint="/api/node" theme="light" />);
    expect(await screen.findByText('First')).toBeTruthy();
    expect(screen.getAllByText('Modal edit')).toHaveLength(2);   // modal_edit -> "Modal edit"
    expect(screen.getByLabelText('Sort by').tagName).toBe('SELECT');
    expect(document.querySelectorAll('.node-list__card')).toHaveLength(2);
  });

  it('requests the endpoint again when the sort order is toggled', async () => {
    mockMedia(true);
    render(<NodeList endpoint="/api/node" theme="light" />);
    await screen.findByText('First');
    fireEvent.click(screen.getByLabelText('Descending'));
    await waitFor(() => expect(apiClient).toHaveBeenLastCalledWith('/api/node', expect.objectContaining({ sort_order: 'ASC' })));
  });

  it('renders the table on wide screens', async () => {
    mockMedia(false);
    render(<NodeList endpoint="/api/node" theme="light" />);
    await waitFor(() => expect(document.querySelector('.rs-table')).not.toBeNull());
    expect(document.querySelector('.node-list__cards')).toBeNull();
  });
});
