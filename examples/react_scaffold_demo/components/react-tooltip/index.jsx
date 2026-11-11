import 'react-tippy/dist/tippy.css';
// Icon font for the `icon` prop.
import 'bootstrap-icons/font/bootstrap-icons.css';
import {TextWithTooltip} from './TextWithTooltip';
import './react-tooltip.scss'
import { createRoot } from 'react-dom/client';

// Props of the SDC (`text`, `icon`) arrive as data-* attributes on the mount element, the trigger text (the `label`
// prop and the content slot) as its text.
(function (Drupal, once) {

  const attachTooltip = (element) => {
    createRoot(element).render(
      <TextWithTooltip text={element.dataset.text} icon={element.dataset.icon} content={element.innerText.trim()}/>
    );
  };

  Drupal.behaviors.reactTooltip = {
    attach(context) {
      once('react', '.react-tooltip', context)
        .forEach((element) => executeWhenVisible(element, attachTooltip, "tooltip"))
    },
  };
})(Drupal, once);
