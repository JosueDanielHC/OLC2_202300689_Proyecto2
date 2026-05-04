// ====================================================
// ARCHIVO 7 - VALIDACION DE REPORTE DE ERRORES
// Proposito: generar errores intencionales para validar
// el reporte de errores de la GUI/API.
// ====================================================

func suma(a int32, b int32) int32 {
	return a + b
}

func main() {
	fmt.Println("=== INICIO PRUEBA REPORTE ERRORES ===")

	// 1) Variable no declarada
	fmt.Println(noDeclarada)

	// 2) Redeclaracion en el mismo ambito
	var repetida int32 = 10
	var repetida int32 = 20

	// 3) Asignacion incompatible de tipos
	var bandera bool = true
	bandera = 123

	// 4) Reasignacion de constante
	const LIMITE int32 = 100
	LIMITE = 200

	// 5) Condicion de if no booleana
	if 10 {
		fmt.Println("Esto no deberia ser valido")
	}

	// 6) break y continue fuera de ciclo
	break
	continue

	// 7) Llamada a built-in con tipo invalido
	fmt.Println(len(999))

	// 8) Llamada a funcion con numero de argumentos incorrecto
	fmt.Println(suma(5))

	// 9) Llamada a funcion con tipo de argumento incorrecto
	fmt.Println(suma(true, 8))

	// 10) Invocacion explicita de main (no permitida por semantica)
	main()

	fmt.Println("=== FIN PRUEBA REPORTE ERRORES ===")
}

/*
Errores esperados (resumen):
- Variable no declarada.
- Identificador redeclarado en el mismo ambito.
- Asignacion de int32 a bool.
- Asignacion a constante.
- Condicion de if no booleana.
- Uso invalido de break.
- Uso invalido de continue.
- len() con tipo invalido.
- Numero de argumentos incorrecto en suma().
- Tipo de argumento incorrecto en suma().
- Invocacion explicita de main().
*/
