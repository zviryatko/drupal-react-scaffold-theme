import { HelloReact } from './HelloReact';
import './hello-react.scss';
import { createRoot } from 'react-dom/client';

(function (Drupal, once) {
  const attach = (element) => {
    // Props arrive as data-* strings, slots as server markup: read both before React replaces the children.
    const name = element.querySelector('.hello-react__name')?.innerText ?? '';
    createRoot(element).render(
      <HelloReact greeting={element.dataset.greeting} name={name} start={Number(element.dataset.start)}/>
    );
  };

  Drupal.behaviors.helloReact = {
    attach(context) {
      once('react', '.hello-react', context)
        .forEach((element) => executeWhenVisible(element, attach, 'hello-react'));
    },
  };
})(Drupal, once);
