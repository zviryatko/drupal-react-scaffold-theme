import { TextWithTooltip } from "../TextWithTooltip";
import { render } from "@testing-library/react";

describe("Component <TextWithTooltip />: ", () => {
  it("should match snapshot", () => {
    const { container, unmount } = render(
      <TextWithTooltip text='Test' content='Test content' />
    );

    expect(container.firstChild).toMatchSnapshot();

    unmount();
  });
});
