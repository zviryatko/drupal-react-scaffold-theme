import { Tooltip } from "react-tippy";

export const TextWithTooltip = ({ text, content }) => (
  <Tooltip
    html={<span className="react-tooltip__text">{text}</span>}
    className="react-tooltip__container"
    position="right"
    delay={100}
  >
    {content}
  </Tooltip>
);
