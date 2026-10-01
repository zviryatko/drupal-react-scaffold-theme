import { useState } from 'react';

export const HelloReact = ({ greeting, name, start = 0 }) => {
  const [count, setCount] = useState(start);
  return (
    <div className="hello-react__inner">
      <p>{greeting}, {name}!</p>
      <button type="button" onClick={() => setCount(count + 1)}>
        {Drupal.t('Clicked @count times', { '@count': count })}
      </button>
    </div>
  );
};
