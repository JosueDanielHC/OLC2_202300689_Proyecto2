.section .rodata
bool_true: .asciz "true"
bool_false: .asciz "false"
type_int32: .asciz "int32"
type_bool: .asciz "bool"
type_string: .asciz "string"
type_float32: .asciz "float32"
type_rune: .asciz "rune"
type_nil: .asciz "nil"
fixed_now: .asciz "2026-04-14 00:00:00"
__newline: .ascii "\n"
__space: .ascii " "
__dot: .ascii "."
__minus: .ascii "-"
float_const_1000: .float 1000.0

.section .bss
.align 3
print_buffer: .skip 128
substr_buffer: .skip 256
concat_buffer: .skip 512
int_buffer: .skip 32

.section .text
.align 2
# Runtime ARM64 para salida y cadenas
.global _start
_start:
# Entrada principal del compilador
bl main
# Finalizacion del proceso
mov x0, #0
mov x8, #93
svc #0

# Inicio de función main
main:
stp x29, x30, [sp, #-16]!
mov x29, sp
sub sp, sp, #16
mov x0, #5
bl __itoa
bl __print_string_slice
bl __print_newline
main_end_1:
add sp, sp, #16
ldp x29, x30, [sp], #16
ret

# write(stdout, x1, x2)
__write_buffer:
mov x0, #1
mov x8, #64
svc #0
ret

# strlen(x0) -> x0
__strlen:
mov x1, x0
mov x2, #0
__strlen_loop:
ldrb w3, [x1, x2]
cmp w3, #0
b.eq __strlen_done
add x2, x2, #1
b __strlen_loop
__strlen_done:
mov x0, x2
ret

# itoa(x0) -> x0 ptr, x1 len
__itoa:
adrp x9, int_buffer
add x9, x9, :lo12:int_buffer
add x10, x9, #31
mov w11, #0
strb w11, [x10]
mov x12, x0
mov x13, #0
cmp x12, #0
b.ge __itoa_positive
neg x12, x12
mov x13, #1
__itoa_positive:
sub x10, x10, #1
mov x14, #10
__itoa_loop:
udiv x15, x12, x14
msub x16, x15, x14, x12
add x16, x16, #48
strb w16, [x10]
mov x12, x15
cmp x12, #0
b.eq __itoa_digits_done
sub x10, x10, #1
b __itoa_loop
__itoa_digits_done:
cmp x13, #0
b.eq __itoa_finish
sub x10, x10, #1
mov w16, #45
strb w16, [x10]
__itoa_finish:
mov x0, x10
adrp x17, int_buffer
add x17, x17, :lo12:int_buffer
add x17, x17, #31
sub x1, x17, x10
ret

# print zero padded 3 digits from w0
__print_uint3:
adrp x9, print_buffer
add x9, x9, :lo12:print_buffer
mov w10, #100
udiv w11, w0, w10
msub w12, w11, w10, w0
mov w10, #10
udiv w13, w12, w10
msub w14, w13, w10, w12
add w11, w11, #48
add w13, w13, #48
add w14, w14, #48
strb w11, [x9]
strb w13, [x9, #1]
strb w14, [x9, #2]
mov x0, x9
mov x1, #3
bl __print_string_slice
ret

# print float32 in s0 with 3 decimals
__print_float_fixed3:
sub sp, sp, #16
str x30, [sp]
fcmp s0, #0.0
b.ge __print_float_positive
adrp x1, __minus
add x1, x1, :lo12:__minus
mov x2, #1
bl __write_buffer
fneg s0, s0
__print_float_positive:
fcvtzs w20, s0
fmov s1, s0
scvtf s2, w20
fsub s1, s1, s2
adrp x10, float_const_1000
add x10, x10, :lo12:float_const_1000
ldr s2, [x10]
fmul s1, s1, s2
fcvtzs w21, s1
sxtw x0, w20
bl __itoa
bl __print_string_slice
adrp x1, __dot
add x1, x1, :lo12:__dot
mov x2, #1
bl __write_buffer
mov w0, w21
bl __print_uint3
ldr x30, [sp]
add sp, sp, #16
ret

# print newline
__print_newline:
adrp x1, __newline
add x1, x1, :lo12:__newline
mov x2, #1
bl __write_buffer
ret

# print single space
__print_space:
adrp x1, __space
add x1, x1, :lo12:__space
mov x2, #1
bl __write_buffer
ret

# print C string pointed by x0
__print_cstr:
mov x9, x0
bl __strlen
mov x2, x0
mov x1, x9
bl __write_buffer
ret

# x0 ptr, x1 len
__print_string_slice:
mov x2, x1
mov x1, x0
bl __write_buffer
ret

# substr(x0 ptr, x1 start, x2 len) -> x0 ptr
__substr:
adrp x9, substr_buffer
add x9, x9, :lo12:substr_buffer
mov x10, #0
__substr_skip:
cmp x10, x1
b.eq __substr_copy_init
ldrb w11, [x0, x10]
cmp w11, #0
b.eq __substr_done_empty
add x10, x10, #1
b __substr_skip
__substr_copy_init:
mov x12, #0
__substr_copy:
cmp x12, x2
b.eq __substr_finish
add x13, x10, x12
ldrb w14, [x0, x13]
cmp w14, #0
b.eq __substr_finish
strb w14, [x9, x12]
add x12, x12, #1
b __substr_copy
__substr_finish:
mov w14, #0
strb w14, [x9, x12]
mov x0, x9
ret
__substr_done_empty:
mov w14, #0
strb w14, [x9]
mov x0, x9
ret

# concat(x0 left, x1 right) -> x0 ptr
__concat_strings:
adrp x9, concat_buffer
add x9, x9, :lo12:concat_buffer
mov x10, #0
__concat_left:
ldrb w11, [x0, x10]
cmp w11, #0
b.eq __concat_right_init
strb w11, [x9, x10]
add x10, x10, #1
b __concat_left
__concat_right_init:
mov x12, #0
__concat_right:
ldrb w13, [x1, x12]
cmp w13, #0
b.eq __concat_finish
add x14, x10, x12
strb w13, [x9, x14]
add x12, x12, #1
b __concat_right
__concat_finish:
add x14, x10, x12
mov w15, #0
strb w15, [x9, x14]
mov x0, x9
ret

