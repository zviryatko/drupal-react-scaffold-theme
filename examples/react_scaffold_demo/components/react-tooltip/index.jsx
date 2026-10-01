import 'react-tippy/dist/tippy.css';
import {TextWithTooltip} from './TextWithTooltip';
import './react-tooltip.scss'
import { createRoot } from 'react-dom/client';

// Props of the SDC (e.g. 'text') arrive as data-* attributes on the mount element.
(function (Drupal, once) {

  const attachTooltip = (element) => {
    createRoot(element).render(
      <TextWithTooltip text={element.dataset.text} content={element.innerText}/>
    );
  };

  Drupal.behaviors.reactTooltip = {
    attach(context) {
      once('react', '.react-tooltip', context)
        .forEach((element) => executeWhenVisible(element, attachTooltip, "tooltip"))
    },
  };
})(Drupal, once);
