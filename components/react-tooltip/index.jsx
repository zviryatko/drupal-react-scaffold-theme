import {TextWithTooltip} from './TextWithTooltip';
import './react-tooltip.scss'
import { createRoot } from 'react-dom/client';

// Values for this component (e.g. 'text') come from the pattern field.
(function (Drupal, $, once) {

  const attachTooltip = (element) => {
    createRoot(element).render(
      <TextWithTooltip text={element.dataset.text} content={element.innerText}/>
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
