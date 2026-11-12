import {NodeList} from './NodeList';
import './node-list.scss'
import { createRoot } from 'react-dom/client';

(function (Drupal, once) {
  const attachNodeList = (element) => {
    createRoot(element).render(
      <NodeList endpoint={element.dataset.endpoint} theme={element.dataset.theme}/>
    );
  };

  Drupal.behaviors.nodeList = {
    attach(context, settings) {
      once('react', '.node-list', context)
        .forEach((element) => executeWhenVisible(element, attachNodeList, "node-list"))
    },
  };
})(Drupal, once);
