import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	Placeholder,
	PanelBody,
	TextControl,
	ToggleControl,
	SelectControl,
	ColorPalette,
	Button,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { useState } from '@wordpress/element';
import metadata from './block.json';

const triState = ( value ) => ( value === undefined ? 'default' : value ? 'yes' : 'no' );
const fromTriState = ( value ) => ( value === 'default' ? undefined : value === 'yes' );

export default function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();
	const [ draft, setDraft ] = useState( '' );
	const { url, file, lines, linesStart, highlight, highlightColor, showLineNumbers, showMeta, copy, scheme } = attributes;

	const triOptions = [
		{ label: __( 'Use plugin default', 'gistful-for-github' ), value: 'default' },
		{ label: __( 'Yes', 'gistful-for-github' ), value: 'yes' },
		{ label: __( 'No', 'gistful-for-github' ), value: 'no' },
	];

	const controls = (
		<InspectorControls>
			<PanelBody title={ __( 'Gist', 'gistful-for-github' ) }>
				<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Gist URL or ID', 'gistful-for-github' ) } value={ url || '' } onChange={ ( v ) => setAttributes( { url: v } ) } />
				<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'File name', 'gistful-for-github' ) } value={ file || '' } onChange={ ( v ) => setAttributes( { file: v } ) } />
				<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Lines (e.g. 2-4, 5- or -3)', 'gistful-for-github' ) } value={ lines || '' } onChange={ ( v ) => setAttributes( { lines: v } ) } />
				<TextControl __nextHasNoMarginBottom __next40pxDefaultSize type="number" label={ __( 'First line number', 'gistful-for-github' ) } value={ linesStart ?? '' } onChange={ ( v ) => setAttributes( { linesStart: v === '' ? undefined : parseInt( v, 10 ) } ) } />
				<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Highlight lines (e.g. 2,5-6)', 'gistful-for-github' ) } value={ highlight || '' } onChange={ ( v ) => setAttributes( { highlight: v } ) } />
			</PanelBody>
			<PanelBody title={ __( 'Display', 'gistful-for-github' ) } initialOpen={ false }>
				<SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Line numbers', 'gistful-for-github' ) } value={ triState( showLineNumbers ) } options={ triOptions } onChange={ ( v ) => setAttributes( { showLineNumbers: fromTriState( v ) } ) } />
				<SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Meta footer', 'gistful-for-github' ) } value={ triState( showMeta ) } options={ triOptions } onChange={ ( v ) => setAttributes( { showMeta: fromTriState( v ) } ) } />
				<SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Copy button', 'gistful-for-github' ) } value={ triState( copy ) } options={ triOptions } onChange={ ( v ) => setAttributes( { copy: fromTriState( v ) } ) } />
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Color scheme', 'gistful-for-github' ) }
					value={ scheme || '' }
					options={ [
						{ label: __( 'Plugin default', 'gistful-for-github' ), value: '' },
						{ label: __( 'Auto', 'gistful-for-github' ), value: 'auto' },
						{ label: __( 'Light', 'gistful-for-github' ), value: 'light' },
						{ label: __( 'Dark', 'gistful-for-github' ), value: 'dark' },
					] }
					onChange={ ( v ) => setAttributes( { scheme: v } ) }
				/>
				<p>{ __( 'Highlight color', 'gistful-for-github' ) }</p>
				<ColorPalette
					colors={ [ { name: __( 'Yellow', 'gistful-for-github' ), color: '#ffffcc' } ] }
					value={ highlightColor }
					onChange={ ( v ) => setAttributes( { highlightColor: v || '' } ) }
				/>
			</PanelBody>
		</InspectorControls>
	);

	if ( ! url ) {
		return (
			<div { ...blockProps }>
				{ controls }
				<Placeholder icon="editor-code" label={ __( 'GitHub Gist', 'gistful-for-github' ) } instructions={ __( 'Paste a gist URL or ID.', 'gistful-for-github' ) }>
					<form onSubmit={ ( e ) => { e.preventDefault(); setAttributes( { url: draft.trim() } ); } }>
						<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Gist URL or ID', 'gistful-for-github' ) } hideLabelFromVision value={ draft } onChange={ setDraft } placeholder="https://gist.github.com/user/id" />
						<Button variant="primary" type="submit">{ __( 'Embed', 'gistful-for-github' ) }</Button>
					</form>
				</Placeholder>
			</div>
		);
	}

	return (
		<div { ...blockProps }>
			{ controls }
			<ServerSideRender block={ metadata.name } attributes={ attributes } />
		</div>
	);
}
