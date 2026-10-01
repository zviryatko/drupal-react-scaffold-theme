import { RecipeExplorer } from "../RecipeExplorer";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";

const response = (rows = [], extra = {}) => ({
  pager: { total_items: rows.length, total_pages: 1, current_page: 0 },
  exposed_filters: [
    { identifier: 'search', label: 'Search', options: [] },
    { identifier: 'difficulty', label: 'Difficulty', options: { easy: 'Easy', hard: 'Hard' } },
    { identifier: 'time', label: 'Time', group_items: { 1: { title: 'Up to 10 minutes' } } },
  ],
  exposed_sorts: [{ field_identifier: 'title', label: 'Title' }],
  rows,
  ...extra,
});

const recipe = { title: 'Soup', url: '/soup', difficulty: 'easy', prep_time: 5, category: 'Starters', summary: '<p>Hot</p>', image: '' };

describe("Component <RecipeExplorer />: ", () => {
  beforeEach(() => {
    window.Drupal = {
      t: (s, args = {}) => Object.entries(args).reduce((r, [k, v]) => r.replace(k, v), s),
      formatPlural: (n, one, many) => (n === 1 ? one : many.replace('@count', n)),
    };
    window.apiClient = jest.fn(() => Promise.resolve(response([recipe])));
    global.apiClient = window.apiClient;
  });

  it("renders recipes and the filters advertised by the endpoint", async () => {
    render(<RecipeExplorer endpoint="/api/recipes" heading="Recipes" />);
    expect(await screen.findByText('Soup')).toBeTruthy();
    expect(screen.getByLabelText('Difficulty').tagName).toBe('SELECT');
    expect(screen.getByLabelText('Search').tagName).toBe('INPUT');
    expect(screen.getByText('Up to 10 minutes')).toBeTruthy();
    expect(screen.getByText('1 recipe found')).toBeTruthy();
  });

  it("requests the endpoint again when a filter changes", async () => {
    render(<RecipeExplorer endpoint="/api/recipes" heading="Recipes" />);
    await screen.findByText('Soup');
    fireEvent.change(screen.getByLabelText('Difficulty'), { target: { value: 'hard' } });
    await waitFor(() => expect(apiClient).toHaveBeenLastCalledWith('/api/recipes', { difficulty: 'hard' }));
  });

  it("shows an empty state", async () => {
    apiClient.mockImplementation(() => Promise.resolve(response([])));
    render(<RecipeExplorer endpoint="/api/recipes" heading="Recipes" />);
    expect(await screen.findByText('No recipes match these filters.')).toBeTruthy();
  });
});
