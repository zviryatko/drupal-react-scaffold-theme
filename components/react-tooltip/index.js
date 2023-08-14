import {TextWithTooltip} from './TextWithTooltip';
import './react-tooltip.scss'

// Values for this component (e.g. 'text') come from the pattern field.
(function (Drupal, $, once) {
  const attachTooltip = (element) => {
    ReactDOM.render(
      <TextWithTooltip text={element.dataset.text} content={element.innerText}/>,
      element
    );
  };

  Drupal.behaviors.reactTooltip = {
    attach(context, settings) {
      const $elements = $(context).find("*").addBack().filter(".react-tooltip");
      once("react", $elements, context)
        .forEach((element) => executeWhenVisible(element, attachTooltip, "tooltip"))
    },
  };
})(Drupal, jQuery, once);
