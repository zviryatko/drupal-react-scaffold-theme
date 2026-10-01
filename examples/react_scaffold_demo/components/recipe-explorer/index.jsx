import { RecipeExplorer } from './RecipeExplorer';
import './recipe-explorer.scss';
import { createRoot } from 'react-dom/client';

(function (Drupal, once) {
  const attachRecipeExplorer = (element) => {
    createRoot(element).render(
      <RecipeExplorer endpoint={element.dataset.endpoint} heading={element.dataset.heading}/>
    );
  };

  Drupal.behaviors.recipeExplorer = {
    attach(context) {
      once('react', '.recipe-explorer', context)
        .forEach((element) => executeWhenVisible(element, attachRecipeExplorer, 'recipe-explorer'));
    },
  };
})(Drupal, once);
