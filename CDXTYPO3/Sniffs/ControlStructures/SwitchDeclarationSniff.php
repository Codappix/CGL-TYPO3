<?php
namespace Codappix\CDXTYPO3\Sniffs\ControlStructures;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

class SwitchDeclarationSniff implements Sniff
{
    public $supportedTokenizers = [
        'PHP',
        'JS',
    ];

    /**
     * The number of spaces code should be indented.
     *
     * @var int
     */
    public $indent = 4;

    public function register()
    {
        return [T_SWITCH];
    }

    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();

        // We can't process SWITCH statements unless we know where they start and end.
        if (isset($tokens[$stackPtr]['scope_opener']) === false
            || isset($tokens[$stackPtr]['scope_closer']) === false
        ) {
            return;
        }

        $switch         = $tokens[$stackPtr];
        $nextCase       = $stackPtr;
        $caseAlignment  = ($switch['column'] + $this->indent);
        $caseCount      = 0;
        $breakAlignment = ($caseAlignment + $this->indent);
        $foundDefault   = false;

        while (($nextCase = $this->findNextCase($phpcsFile, ($nextCase + 1), $switch['scope_closer'])) !== false) {
            if ($tokens[$nextCase]['code'] === T_DEFAULT) {
                $type         = 'default';
                $foundDefault = true;
            } else {
                $type = 'case';
                $caseCount++;
            }

            // Check if the keyword "switch", "default" or "case" is written lowercased.
            if ($tokens[$nextCase]['content'] !== strtolower($tokens[$nextCase]['content'])) {
                $expected = strtolower($tokens[$nextCase]['content']);
                $error    = '%s keyword must be lowercase; expected "%s" but found "%s"';
                $data     = array(
                             strtoupper($type),
                             $expected,
                             $tokens[$nextCase]['content'],
                            );

                $fix = $phpcsFile->addFixableError($error, $nextCase, $type . 'NotLower', $data);
                if ($fix === true) {
                    $phpcsFile->fixer->replaceToken($nextCase, $expected);
                }
            }

            // Check if the alignment / column is correct in relation to the switch keyword.
            if ($tokens[$nextCase]['column'] !== $caseAlignment) {
                $error = '%s keyword must be indented %s tab from SWITCH keyword';
                $data  = array(
                          strtoupper($type),
                          $this->indent,
                         );
                $phpcsFile->addError($error, $nextCase, $type . 'Indent', $data);
            }

            // Check if the case statement is follow by a single space.
            if ($type === 'case'
                && ($tokens[($nextCase + 1)]['code'] !== T_WHITESPACE
                || $tokens[($nextCase + 1)]['content'] !== ' ')
            ) {
                $error = 'CASE keyword should be followed by a single space';
                $fix   = $phpcsFile->addFixableError($error, $nextCase, 'SpacingAfterCase');
                if ($fix === true) {
                    if ($tokens[($nextCase + 1)]['code'] !== T_WHITESPACE) {
                        $phpcsFile->fixer->addContent($nextCase, ' ');
                    } else {
                        $phpcsFile->fixer->replaceToken(($nextCase + 1), ' ');
                    }
                }
            }

            $opener = $tokens[$nextCase]['scope_opener'];
            if ($tokens[$opener]['code'] === T_COLON) {
                if ($tokens[($opener - 1)]['code'] === T_WHITESPACE) {
                    $error = 'There must be no space before the colon in a %s statement';
                    $data  = array(strtoupper($type));
                    $fix   = $phpcsFile->addFixableError(
                        $error,
                        $nextCase,
                        'SpaceBeforeColon' . strtoupper($type),
                        $data
                    );
                    if ($fix === true) {
                        $phpcsFile->fixer->replaceToken(($opener - 1), '');
                    }
                }
            } else {
                $error = strtoupper($type) . ' statements must not defined using curly braces';
                $phpcsFile->addError($error, $nextCase, 'WrongOpener' . $type);
            }

            $nextCloser = $tokens[$nextCase]['scope_closer'];
            if ($tokens[$nextCloser]['scope_condition'] === $nextCase) {
                // Only need to check some things once, even if the closer
                // is shared between multiple case statements, or even the default
                // case.
                $diff = ($caseAlignment + $this->indent - $tokens[$nextCloser]['column']);
                if ($diff !== 0) {
                    $error = 'Terminating statement must be indented to the same level as the CASE body';
                    $fix   = $phpcsFile->addFixableError(
                        $error,
                        $nextCloser,
                        'BreakIndent'
                    );
                    if ($fix === true) {
                        if ($diff > 0) {
                            $phpcsFile->fixer->addContentBefore($nextCloser, str_repeat(' ', $diff));
                        } else {
                            $phpcsFile->fixer->substrToken(($nextCloser - 1), 0, $diff);
                        }
                    }
                }
            }

            $nextBreak = $tokens[$nextCase]['scope_closer'];
            if ($type === 'default') {
                // Check if the default statement scope include a break statement.
                if ($tokens[$nextBreak]['code'] === T_BREAK) {
                    $error = 'The "default" statement must not have a "break" statement.';
                    $phpcsFile->addError($error, $nextCase, 'DefaultNoBreak');

                    // Check if the default statement is not the last statement
                    // in switch. If the default contains a throw, exit, continue
                    // or return statement this will be chosen as scope_closer
                    // But the default statement can contain a such statements.
                } elseif (($tokens[$nextCase]['scope_closer'] !== $switch['scope_closer'])
                    && $tokens[$tokens[$nextCase]['scope_closer']]['code'] !== T_THROW
                    && $tokens[$tokens[$nextCase]['scope_closer']]['code'] !== T_EXIT
                    && $tokens[$tokens[$nextCase]['scope_closer']]['code'] !== T_CONTINUE
                    && $tokens[$tokens[$nextCase]['scope_closer']]['code'] !== T_RETURN
                ) {
                    $error = 'The "default" statement must be the last in the switch.';
                    $phpcsFile->addError($error, $nextCase, 'DefaultNotLastInSwitch');
                }
            } elseif ($tokens[$nextBreak]['code'] === T_BREAK
                || $tokens[$nextBreak]['code'] === T_RETURN
                || $tokens[$nextBreak]['code'] === T_CONTINUE
                || $tokens[$nextBreak]['code'] === T_THROW
            ) {
                if ($tokens[$nextBreak]['scope_condition'] === $nextCase) {
                    if ($tokens[$nextBreak]['column'] !== $breakAlignment) {
                        $error = 'Case breaking statement must be indented %s tab from CASE keyword';
                        $data  = array($this->indent);
                        $phpcsFile->addError(
                            $error,
                            $nextBreak,
                            'BreakIndent',
                            $data
                        );
                    }

                    $breakLine = $tokens[$nextBreak]['line'];
                    $prevLine  = 0;
                    for ($i = ($nextBreak - 1); $i > $stackPtr; $i--) {
                        if ($tokens[$i]['type'] !== 'T_WHITESPACE') {
                            $prevLine = $tokens[$i]['line'];
                            break;
                        }
                    }

                    if ($prevLine !== ($breakLine - 1)) {
                        $error = 'There should no blank lines before case breaking statements';
                        $phpcsFile->addWarning(
                            $error,
                            $nextBreak,
                            'SpacingBeforeBreak'
                        );
                    }

                    $semicolon = $phpcsFile->findNext(T_SEMICOLON, $nextBreak);
                    for ($i = ($semicolon + 1); $i < $tokens[$stackPtr]['scope_closer']; $i++) {
                        if ($tokens[$i]['type'] === 'T_BREAK') {
                            $error = 'Only one break statement is allowed per case.';
                            $phpcsFile->addError(
                                $error,
                                $i,
                                'FoundMultipleBreaksPerCase'
                            );
                        }

                        if ($tokens[$i]['type'] !== 'T_WHITESPACE') {
                            break;
                        }
                    }

                    $caseLine = $tokens[$nextCase]['line'];
                    $nextLine = $tokens[$nextBreak]['line'];
                    for ($i = ($opener + 1); $i < $nextBreak; $i++) {
                        if ($tokens[$i]['type'] !== 'T_WHITESPACE') {
                            $nextLine = $tokens[$i]['line'];
                            break;
                        }
                    }

                    if ($nextLine !== ($caseLine + 1)) {
                        $error = 'There should be no blank lines after %s statements';
                        $data  = array(strtoupper($type));
                        $phpcsFile->addWarning(
                            $error,
                            $nextCase,
                            'SpacingAfter' . $type,
                            $data
                        );
                    }
                }
            }

            // We only want cases from here on on.
            if ($type !== 'case') {
                continue;
            }

            // Falling through a case have to be indicated by a comment.
            $afterNextCase = $phpcsFile->findNext(T_CASE, ($nextCase + 1));
            if (($afterNextCase !== false)
                && ($type !== 'default')
                && (($tokens[$nextCase]['scope_closer']) === ($tokens[$afterNextCase]['scope_closer']))
            ) {
                $commentBeforeCase = $phpcsFile->findPrevious(
                    T_COMMENT,
                    $afterNextCase,
                    $nextCase
                );
                if (($tokens[$commentBeforeCase]['line']) !== ($tokens[$afterNextCase]['line'] - 1)
                ) {
                    $error = 'If one case block has to pass control into another case block without having a break,' .
                       ' there must be a comment about it in the code.';
                    $phpcsFile->addError(
                        $error,
                        $nextCase,
                        'CaseWithoutBreakNoCommentFound'
                    );
                }
            }
        }

        if ($foundDefault === false) {
            $error = 'All SWITCH statements must contain a DEFAULT case';
            $phpcsFile->addError($error, $stackPtr, 'MissingDefault');
        }

        if ($tokens[$switch['scope_closer']]['column'] !== $switch['column']) {
            $error = 'Closing brace of SWITCH statement must be aligned with SWITCH keyword';
            $phpcsFile->addError($error, $switch['scope_closer'], 'CloseBraceAlign');
        }

        if ($caseCount === 0) {
            $error = 'SWITCH statements must contain at least one CASE statement';
            $phpcsFile->addError($error, $stackPtr, 'MissingCase');
        }
    }

    /**
     * Find the next CASE or DEFAULT statement from a point in the file.
     *
     * Note that nested switches are ignored.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the current token.
     * @param int $end The position to stop looking at.
     *
     * @return int | bool
     */
    private function findNextCase(File $phpcsFile, $stackPtr, $end)
    {
        $tokens = $phpcsFile->getTokens();
        while (($stackPtr = $phpcsFile->findNext(array(T_CASE, T_DEFAULT, T_SWITCH), $stackPtr, $end)) !== false) {
            // Skip nested SWITCH statements; they are handled on their own.
            if ($tokens[$stackPtr]['code'] === T_SWITCH) {
                $stackPtr = $tokens[$stackPtr]['scope_closer'];
                continue;
            }

            break;
        }

        return $stackPtr;
    }
}
