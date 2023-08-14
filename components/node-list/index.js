import {NodeList} from './NodeList';
import './node-list.scss'

(function (Drupal, $, once) {
  const attachNodeList = (element) => {
    ReactDOM.render(
      <NodeList endpoint={element.dataset.endpoint} theme={element.dataset.theme}/>,
      element
    );
  };

  Drupal.behaviors.nodeList = {
    attach(context, settings) {
      const $elements = $(context).find("*").addBack().filter(".node-list");
      once("react", $elements, context)
        .forEach((element) => executeWhenVisible(element, attachNodeList, "node-list"))
    },
  };
})(Drupal, jQuery, once);
