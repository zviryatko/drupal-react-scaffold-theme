import { Tooltip } from "react-tippy";

export const TextWithTooltip = ({ text, content, icon }) => (
  <Tooltip
    html={<span className="react-tooltip__text">{text}</span>}
    className="react-tooltip__container"
    position="right"
    delay={100}
    animateFill={false}
  >
    {icon && <i className={`bi bi-${icon}`} aria-hidden="true" />}
    {icon && content ? ' ' : null}
    {content}
  </Tooltip>
);
