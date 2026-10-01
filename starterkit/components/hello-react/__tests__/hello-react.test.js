import { HelloReact } from '../HelloReact';
import { render, screen, fireEvent } from '@testing-library/react';

describe('<HelloReact />', () => {
  beforeEach(() => {
    window.Drupal = { t: (s, args = {}) => Object.entries(args).reduce((r, [k, v]) => r.replace(k, v), s) };
  });

  it('greets and counts clicks', () => {
    render(<HelloReact greeting="Hi" name="Ann" start={2} />);
    expect(screen.getByText('Hi, Ann!')).toBeTruthy();
    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByText('Clicked 3 times')).toBeTruthy();
  });
});
