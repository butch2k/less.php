<?php


class Less_processExtendsVisitor extends Less_visitor{

	public $allExtendsStack;

	public $visitRuleDeeper = false;
	public $visitMixinDefinitionDeeper = false;
	public $visitSelectorDeeper = false;



	function run( $root ){
		$extendFinder = new Less_extendFinderVisitor();
		$extendFinder->run( $root );
		if( !$extendFinder->foundExtends) { return $root; }

		$root->allExtends = array_merge($root->allExtends, $this->doExtendChaining( $root->allExtends, $root->allExtends));
		$this->allExtendsStack = array();
		$this->allExtendsStack[] = &$root->allExtends;

		$this->visit( $root );
	}

	function doExtendChaining( $extendsList, $extendsListTarget, $iterationCount = 0){
		//
		// chaining is different from normal extension.. if we extend an extend then we are not just copying, altering and pasting
		// the selector we would do normally, but we are also adding an extend with the same target selector
		// this means this new extend can then go and alter other extends
		//
		// this method deals with all the chaining work - without it, extend is flat and doesn't work on other extend selectors
		// this is also the most expensive.. and a match on one selector can cause an extension of a selector we had already processed if
		// we look at each selector at a time, as is done in visitRuleset

		$extendsToAdd = array();
		$extendVisitor = $this;


		//loop through comparing every extend with every target extend.
		// a target extend is the one on the ruleset we are looking at copy/edit/pasting in place
		// e.g. .a:extend(.b) {} and .b:extend(.c) {} then the first extend extends the second one
		// and the second is the target.
		// the seperation into two lists allows us to process a subset of chains with a bigger set, as is the
		// case when processing media queries
		for( $extendIndex = 0; $extendIndex < count($extendsList); $extendIndex++ ){
			for( $targetExtendIndex = 0; $targetExtendIndex < count($extendsListTarget); $targetExtendIndex++ ){

				$extend = $extendsList[$extendIndex];
				$targetExtend = $extendsListTarget[$targetExtendIndex];

				// look for circular references
				if( $this->inInheritanceChain( $targetExtend, $extend)) {
					continue;
				}

				// find a match in the target extends self selector (the bit before :extend)
				$selectorPath = array( $targetExtend->selfSelectors[0] );
				$matches = $extendVisitor->findMatch( $extend, $selectorPath);


				if( count($matches) ){

					// we found a match, so for each self selector..
					foreach($extend->selfSelectors as $selfSelector ){


						// process the extend as usual
						$newSelector = $extendVisitor->extendSelector( $matches, $selectorPath, $selfSelector);

						// but now we create a new extend from it
						$newExtend = new Less_Tree_Extend( $targetExtend->selector, $targetExtend->option, 0);
						$newExtend->selfSelectors = $newSelector;

						// add the extend onto the list of extends for that selector
						$newSelector[ count($newSelector)-1]->extendList = array($newExtend);

						// record that we need to add it.
						$extendsToAdd[] = $newExtend;
						$newExtend->ruleset = $targetExtend->ruleset;

						//remember its parents for circular references
						$newExtend->parents = array($targetExtend, $extend);

						// only process the selector once.. if we have :extend(.a,.b) then multiple
						// extends will look at the same selector path, so when extending
						// we know that any others will be duplicates in terms of what is added to the css
						if( $targetExtend->firstExtendOnThisSelectorPath ){
							$newExtend->firstExtendOnThisSelectorPath = true;
							$targetExtend->ruleset->paths[] = $newSelector;
						}
					}
				}
			}
		}

		if( count($extendsToAdd) ){
			// try to detect circular references to stop a stack overflow.
			// may no longer be needed.			$this->extendChainCount++;
			if( $iterationCount > 100) {
				$selectorOne = "{unable to calculate}";
				$selectorTwo = "{unable to calculate}";
				try{
					$selectorOne = $extendsToAdd[0]->selfSelectors[0]->toCSS();
					$selectorTwo = $extendsToAdd[0]->selector->toCSS();
				}catch(\Exception $e){}
				throw new Less_ParserException("extend circular reference detected. One of the circular extends is currently:"+$selectorOne+":extend(" + $selectorTwo+")");
			}

			// now process the new extends on the existing rules so that we can handle a extending b extending c ectending d extending e...
			return array_merge($extendsToAdd, $extendVisitor->doExtendChaining( $extendsToAdd, $extendsListTarget, $iterationCount+1));
		} else {
			return $extendsToAdd;
		}
	}

	function inInheritanceChain( $possibleParent, $possibleChild ){

		if( $possibleParent === $possibleChild) {
			return true;
		}

		if( $possibleChild->parents ){
			if( $this->inInheritanceChain( $possibleParent, $possibleChild->parents[0]) ){
				return true;
			}
			if( $this->inInheritanceChain( $possibleParent, $possibleChild->parents[1]) ){
				return true;
			}
		}
		return false;
	}


	function visitRuleset($rulesetNode) {
		if( $rulesetNode->root ){
			return;
		}

		$allExtends = $this->allExtendsStack[ count($this->allExtendsStack)-1];
		$selectorsToAdd = array();
		$extendVisitor = $this;

		// look at each selector path in the ruleset, find any extend matches and then copy, find and replace

		for( $extendIndex = 0; $extendIndex < count($allExtends); $extendIndex++ ){
			for($pathIndex = 0; $pathIndex < count($rulesetNode->paths); $pathIndex++ ){

				$selectorPath = $rulesetNode->paths[$pathIndex];

				// extending extends happens initially, before the main pass
				if( count( $selectorPath[ count($selectorPath)-1]->extendList) ) { continue; }

				$matches = $this->findMatch($allExtends[$extendIndex], $selectorPath);



				if( count($matches) ){
					foreach($allExtends[$extendIndex]->selfSelectors as $selfSelector ){
						$selectorsToAdd[] = $extendVisitor->extendSelector($matches, $selectorPath, $selfSelector);
					}

				}
			}
		}
		$rulesetNode->paths = array_merge($rulesetNode->paths,$selectorsToAdd);
	}

	function findMatch($extend, $haystackSelectorPath ){

		//
		// look through the haystack selector path to try and find the needle - extend.selector
		// returns an array of selector matches that can then be replaced
		//
		$needleElements = $extend->selector->elements;
		//$extendVisitor = $this;
		$potentialMatches = array();
		$potentialMatch = null;
		$matches = array();

		// loop through the haystack elements
		for($haystackSelectorIndex = 0, $haystack_path_len = count($haystackSelectorPath); $haystackSelectorIndex < $haystack_path_len; $haystackSelectorIndex++ ){
			$hackstackSelector = $haystackSelectorPath[$haystackSelectorIndex];

			for($hackstackElementIndex = 0, $haystack_elements_len = count($hackstackSelector->elements); $hackstackElementIndex < $haystack_elements_len; $hackstackElementIndex++ ){

				$haystackElement = $hackstackSelector->elements[$hackstackElementIndex];

				// if we allow elements before our match we can add a potential match every time. otherwise only at the first element.
				if( $extend->allowBefore || ($haystackSelectorIndex == 0 && $hackstackElementIndex == 0) ){
					$potentialMatches[] = array('pathIndex'=> $haystackSelectorIndex, 'index'=> $hackstackElementIndex, 'matched'=> 0, 'initialCombinator'=> $haystackElement->combinator);
				}

				for($i = 0; $i < count($potentialMatches); $i++ ){
					$potentialMatch = &$potentialMatches[$i];

					// selectors add " " onto the first element. When we use & it joins the selectors together, but if we don't
					// then each selector in haystackSelectorPath has a space before it added in the toCSS phase. so we need to work out
					// what the resulting combinator will be
					$targetCombinator = $haystackElement->combinator->value;
					if( $targetCombinator == '' && $hackstackElementIndex === 0 ){
						$targetCombinator = ' ';
					}

					// if we don't match, null our match to indicate failure
					if( !$this->isElementValuesEqual( $needleElements[$potentialMatch['matched'] ]->value, $haystackElement->value) ||
						($potentialMatch['matched'] > 0 && $needleElements[ $potentialMatch['matched'] ]->combinator->value !== $targetCombinator) ){
						$potentialMatch = null;
					} else {
						$potentialMatch['matched']++;
					}

					// if we are still valid and have finished, test whether we have elements after and whether these are allowed
					if( $potentialMatch ){
						$potentialMatch['finished'] = ($potentialMatch['matched'] === count($needleElements) );

						if( $potentialMatch['finished'] &&
							(!$extend->allowAfter && ($hackstackElementIndex+1 < count($hackstackSelector->elements) || $haystackSelectorIndex+1 < count($haystackSelectorPath))) ){
							$potentialMatch = null;
						}
					}
					// if null we remove, if not, we are still valid, so either push as a valid match or continue
					if( $potentialMatch ){
						if( $potentialMatch['finished'] ){
							$potentialMatch['length'] = count($needleElements);
							$potentialMatch['endPathIndex'] = $haystackSelectorIndex;
							$potentialMatch['endPathElementIndex'] = $hackstackElementIndex + 1; // index after end of match
							$potentialMatches = array(); // we don't allow matches to overlap, so start matching again
							$matches[] = $potentialMatch;
						}
					} else {
						array_splice($potentialMatches, $i, 1);
						$i--;
					}
				}
			}
		}
		return $matches;
	}

	function isElementValuesEqual( $elementValue1, $elementValue2 ){

		if( is_string($elementValue1) || is_string($elementValue2) ) {
			return $elementValue1 === $elementValue2;
		}

		if( $elementValue1 instanceof Less_Tree_Attribute ){

			if( $elementValue1->op !== $elementValue2->op || $elementValue1->key !== $elementValue2->key ){
				return false;
			}

			if( !$elementValue1->value || !$elementValue2->value ){
				if( $elementValue1->value || $elementValue2->value ) {
					return false;
				}
				return true;
			}
			$elementValue1 = ($elementValue1->value->value ? $elementValue1->value->value : $elementValue1->value );
			$elementValue2 = ($elementValue2->value->value ? $elementValue2->value->value : $elementValue2->value );
			return $elementValue1 === $elementValue2;
		}
		return false;
	}

	function extendSelector($matches, $selectorPath, $replacementSelector){

		//for a set of matches, replace each match with the replacement selector

		$currentSelectorPathIndex = 0;
		$currentSelectorPathElementIndex = 0;
		$path = array();

		for($matchIndex = 0; $matchIndex < count($matches); $matchIndex++ ){


			$match = $matches[$matchIndex];
			$selector = $selectorPath[ $match['pathIndex'] ];
			$firstElement = new Less_Tree_Element(
				$match['initialCombinator'],
				$replacementSelector->elements[0]->value,
				$replacementSelector->elements[0]->index
			);

			if( $match['pathIndex'] > $currentSelectorPathIndex && $currentSelectorPathElementIndex > 0 ){
				$path[ count($path)-1]->elements = array_merge( $path[ count($path) - 1]->elements, array_slice( $selectorPath[$currentSelectorPathIndex]->elements, $currentSelectorPathElementIndex));
				$currentSelectorPathElementIndex = 0;
				$currentSelectorPathIndex++;
			}

			$slice_len = $match['pathIndex'] - $currentSelectorPathIndex;
			$temp = array_slice($selectorPath, $currentSelectorPathIndex, $slice_len);
			$path = array_merge( $path, $temp);

			$slice_len = $match['index'] - $currentSelectorPathElementIndex;
			$new_elements = array_slice($selector->elements,$currentSelectorPathElementIndex, $slice_len);
			$new_elements = array_merge($new_elements, array($firstElement) );
			$new_elements = array_merge($new_elements, array_slice($replacementSelector->elements,1) );
			$path[] = new Less_Tree_Selector( $new_elements );

			$currentSelectorPathIndex = $match['endPathIndex'];
			$currentSelectorPathElementIndex = $match['endPathElementIndex'];
			if( $currentSelectorPathElementIndex >= count($selector->elements) ){
				$currentSelectorPathElementIndex = 0;
				$currentSelectorPathIndex++;
			}
		}

		if( $currentSelectorPathIndex < count($selectorPath) && $currentSelectorPathElementIndex > 0 ){
			$path[ count($path) - 1]->elements = array_merge( $path[ count($path) - 1]->elements, array_slice($selectorPath[$currentSelectorPathIndex]->elements, $currentSelectorPathElementIndex));
			$currentSelectorPathElementIndex = 0;
			$currentSelectorPathIndex++;
		}

		$slice_len = count($selectorPath) - $currentSelectorPathIndex;
		$path = array_merge($path, array_slice($selectorPath, $currentSelectorPathIndex, $slice_len));

		return $path;
	}


	function visitMedia( $mediaNode ){
		$newAllExtends = array_merge( $mediaNode->allExtends, $this->allExtendsStack[ count($this->allExtendsStack)-1 ] );
		$newAllExtends = array_merge($newAllExtends, $this->doExtendChaining($newAllExtends, $mediaNode->allExtends));
		$this->allExtendsStack[] = $newAllExtends;
	}

	function visitMediaOut( $mediaNode ){
		array_pop( $this->allExtendsStack );
	}

	function visitDirective( $directiveNode ){
		$temp = $this->allExtendsStack[ count($this->allExtendsStack)-1];
		$newAllExtends = array_merge( $directiveNode->allExtends, $temp );
		$newAllExtends = array_merge($newAllExtends, $this->doExtendChaining($newAllExtends, $directiveNode->allExtends));
		$this->allExtendsStack[] = $newAllExtends;
	}

	function visitDirectiveOut( $directiveNode ){
		array_pop($this->allExtendsStack);
	}

}