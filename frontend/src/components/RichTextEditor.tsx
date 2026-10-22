import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import TextAlign from '@tiptap/extension-text-align';
import Underline from '@tiptap/extension-underline';
import { EditorContent, useEditor, type Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import {
  AlignCenter,
  AlignLeft,
  AlignRight,
  Bold,
  Italic,
  Link2,
  Link2Off,
  List,
  ListOrdered,
  Redo2,
  RemoveFormatting,
  Underline as UnderlineIcon,
  Undo2,
} from 'lucide-react';
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { Button, Dialog, Field, Input } from '@/components/ui';
import { cn } from '@/lib/utils';

const MERGE_TAGS = [
  { token: '{{first_name}}', label: 'First name' },
  { token: '{{last_name}}', label: 'Last name' },
  { token: '{{company_name}}', label: 'Company' },
  { token: '{{email}}', label: 'Email' },
  { token: '{{website}}', label: 'Website' },
];

function ToolbarButton({
  onClick,
  active,
  disabled,
  label,
  children,
}: {
  onClick: () => void;
  active?: boolean;
  disabled?: boolean;
  label: string;
  children: ReactNode;
}) {
  return (
    <button
      type="button"
      onMouseDown={(event) => event.preventDefault()}
      onClick={onClick}
      disabled={disabled}
      title={label}
      aria-label={label}
      aria-pressed={active}
      className={cn(
        'flex h-8 w-8 items-center justify-center rounded-md transition-colors disabled:opacity-40',
        active ? 'bg-brand-soft text-brand-strong' : 'text-ink-soft hover:bg-muted hover:text-ink',
      )}
    >
      {children}
    </button>
  );
}

function Divider() {
  return <span className="mx-1 h-5 w-px bg-line" aria-hidden />;
}

function LinkDialog({ editor, onClose }: { editor: Editor; onClose: () => void }) {
  const [url, setUrl] = useState(editor.getAttributes('link').href ?? 'https://');
  const [error, setError] = useState<string | null>(null);

  function apply() {
    const trimmed = url.trim();

    // Only http(s) and mailto survive server-side sanitization, so reject
    // anything else here rather than letting it be silently stripped later.
    if (!/^(https?:\/\/|mailto:)/i.test(trimmed)) {
      setError('Links must start with https://, http:// or mailto:');
      return;
    }

    editor.chain().focus().extendMarkRange('link').setLink({ href: trimmed }).run();
    onClose();
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Insert link"
      width="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" onClick={apply}>
            Apply link
          </Button>
        </>
      }
    >
      <Field label="Destination URL" error={error} hint="Tracked links are rewritten at send time.">
        <Input
          value={url}
          onChange={(event) => setUrl(event.target.value)}
          autoFocus
          onKeyDown={(event) => event.key === 'Enter' && apply()}
        />
      </Field>
    </Dialog>
  );
}

export function RichTextEditor({
  value,
  onChange,
  placeholder = 'Write your email…',
}: {
  value: string;
  onChange: (html: string) => void;
  placeholder?: string;
}) {
  const [linkOpen, setLinkOpen] = useState(false);

  const editor = useEditor({
    extensions: [
      StarterKit.configure({
        heading: { levels: [1, 2, 3] },
        link: false,
        underline: false,
      }),
      Underline,
      Link.configure({
        openOnClick: false,
        autolink: true,
        // Matches the server-side sanitizer's allow-list.
        protocols: ['http', 'https', 'mailto'],
        HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
      }),
      TextAlign.configure({ types: ['heading', 'paragraph'] }),
      Placeholder.configure({ placeholder }),
    ],
    content: value || '',
    editorProps: {
      attributes: { class: 'focus:outline-none' },
    },
    onUpdate: ({ editor: instance }) => onChange(instance.getHTML()),
  });

  // Keep the editor in sync when the document is replaced from outside
  // (loading a saved draft), without clobbering what the user is typing.
  useEffect(() => {
    if (!editor) return;
    if (value !== editor.getHTML()) {
      editor.commands.setContent(value || '', { emitUpdate: false });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editor, value]);

  const insertToken = useCallback(
    (token: string) => {
      editor?.chain().focus().insertContent(token).run();
    },
    [editor],
  );

  if (!editor) {
    return <div className="h-80 animate-pulse rounded-lg border border-line bg-muted/40" />;
  }

  return (
    <div className="overflow-hidden rounded-lg border border-line-strong bg-surface">
      <div className="flex flex-wrap items-center gap-0.5 border-b border-line bg-muted/40 px-2 py-1.5">
        <ToolbarButton label="Bold" active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()}>
          <Bold className="h-4 w-4" />
        </ToolbarButton>
        <ToolbarButton label="Italic" active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()}>
          <Italic className="h-4 w-4" />
        </ToolbarButton>
        <ToolbarButton
          label="Underline"
          active={editor.isActive('underline')}
          onClick={() => editor.chain().focus().toggleUnderline().run()}
        >
          <UnderlineIcon className="h-4 w-4" />
        </ToolbarButton>

        <Divider />

        <ToolbarButton
          label="Bulleted list"
          active={editor.isActive('bulletList')}
          onClick={() => editor.chain().focus().toggleBulletList().run()}
        >
          <List className="h-4 w-4" />
        </ToolbarButton>
        <ToolbarButton
          label="Numbered list"
          active={editor.isActive('orderedList')}
          onClick={() => editor.chain().focus().toggleOrderedList().run()}
        >
          <ListOrdered className="h-4 w-4" />
        </ToolbarButton>

        <Divider />

        <ToolbarButton
          label="Align left"
          active={editor.isActive({ textAlign: 'left' })}
          onClick={() => editor.chain().focus().setTextAlign('left').run()}
        >
          <AlignLeft className="h-4 w-4" />
        </ToolbarButton>
        <ToolbarButton
          label="Align centre"
          active={editor.isActive({ textAlign: 'center' })}
          onClick={() => editor.chain().focus().setTextAlign('center').run()}
        >
          <AlignCenter className="h-4 w-4" />
        </ToolbarButton>
        <ToolbarButton
          label="Align right"
          active={editor.isActive({ textAlign: 'right' })}
          onClick={() => editor.chain().focus().setTextAlign('right').run()}
        >
          <AlignRight className="h-4 w-4" />
        </ToolbarButton>

        <Divider />

        <ToolbarButton label="Insert link" active={editor.isActive('link')} onClick={() => setLinkOpen(true)}>
          <Link2 className="h-4 w-4" />
        </ToolbarButton>
        <ToolbarButton
          label="Remove link"
          disabled={!editor.isActive('link')}
          onClick={() => editor.chain().focus().unsetLink().run()}
        >
          <Link2Off className="h-4 w-4" />
        </ToolbarButton>
        <ToolbarButton
          label="Clear formatting"
          onClick={() => editor.chain().focus().unsetAllMarks().clearNodes().run()}
        >
          <RemoveFormatting className="h-4 w-4" />
        </ToolbarButton>

        <Divider />

        <ToolbarButton label="Undo" disabled={!editor.can().undo()} onClick={() => editor.chain().focus().undo().run()}>
          <Undo2 className="h-4 w-4" />
        </ToolbarButton>
        <ToolbarButton label="Redo" disabled={!editor.can().redo()} onClick={() => editor.chain().focus().redo().run()}>
          <Redo2 className="h-4 w-4" />
        </ToolbarButton>

        <div className="ml-auto flex flex-wrap items-center gap-1 py-0.5">
          <span className="px-1 text-[11px] text-ink-faint">Insert:</span>
          {MERGE_TAGS.map((tag) => (
            <button
              key={tag.token}
              type="button"
              onMouseDown={(event) => event.preventDefault()}
              onClick={() => insertToken(tag.token)}
              title={`Insert ${tag.label}`}
              className="rounded-md border border-line bg-surface px-1.5 py-0.5 font-mono text-[11px] text-ink-soft hover:border-brand hover:text-brand"
            >
              {tag.token}
            </button>
          ))}
        </div>
      </div>

      <div className="editor-content">
        <EditorContent editor={editor} />
      </div>

      {linkOpen && <LinkDialog editor={editor} onClose={() => setLinkOpen(false)} />}
    </div>
  );
}
